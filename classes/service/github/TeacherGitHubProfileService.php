<?php
namespace mod_pluginpatroller\service\github;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\TeacherRepoModel;
use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\helpers\ConfigHelper;
use cache;

/**
 * Servicio para gestión del perfil GitHub de profesores:
 * - Obtener y cachear username de GitHub del profesor
 * - Validar y guardar username de GitHub
 * - Manejar cambios de username (desasignar repos del username anterior)
 * - Gestionar caché de usernames
 */
class TeacherGitHubProfileService {
    
    private $course;
    private $user;
    private $githubAPI;
    private $cache;
    
    const CACHE_KEY_PREFIX = 'github_username_';
    
    public function __construct($course, $user) {
        $this->course = $course;
        $this->user = $user;
        
        // Inicializar caché
        try {
            $this->cache = cache::make('mod_pluginpatroller', 'teacher_github_usernames');
        } catch (\Exception $e) {
            debugging('Error inicializando caché: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->cache = null;
        }
        
        // Inicializar GitHub API usando servicio centralizado
        try {
            $apiService = new GitHubApiService();
            $this->githubAPI = $apiService->getAPI();
        } catch (\Exception $e) {
            debugging('Error inicializando GitHub API: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->githubAPI = null;
        }
    }
    
    //Obtiene el username de GitHub del profesor
    public function getGithubUsername(): string {
        $cache_key = self::CACHE_KEY_PREFIX . $this->user->id;
        
        // Intentar obtener de caché
        if ($this->cache !== null) {
            $cached_value = $this->cache->get($cache_key);
            
            if ($cached_value !== false && !empty($cached_value)) {
                return $cached_value;
            }
        }
        
        // Obtener de BD
        $username = TeacherRepoModel::getTeacherGithubUsername($this->user->id);
        
        // Actualizar caché si se encontró
        if (!empty($username) && $this->cache !== null) {
            $this->cache->set($cache_key, $username);
        }
        
        return $username ?? '';
    }
    
    //Guarda el nombre de usuario GitHub del profesor
    public function saveGithubUsername(string $github_username) {
        $github_username = trim($github_username);
        
        // Validar formato
        try {
            $this->validateUsernameFormat($github_username);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        
        // Verificar existencia en GitHub
        if (!$this->verifyUsernameInGithub($github_username)) {
            return "El usuario '$github_username' no existe en GitHub";
        }
        
        // Detectar cambio de username
        $current_username = $this->getGithubUsername();
        $username_changed = !empty($current_username) && $current_username !== $github_username;
        
        // Manejar cambio si es necesario
        if ($username_changed) {
            $this->handleUsernameChange($current_username, $github_username);
        }
        
        // Actualizar caché
        $this->updateUsernameCache($github_username);
        
        return true;
    }

    //Valida el formato del username de GitHub
    private function validateUsernameFormat(string $username): void {
        if (empty($username)) {
            throw new \InvalidArgumentException('El nombre de usuario no puede estar vacío');
        }
        
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $username)) {
            throw new \InvalidArgumentException('El nombre de usuario solo puede contener letras, números y guiones');
        }
    }

    //Verifica si el username existe en GitHub
    private function verifyUsernameInGithub(string $username): bool {
        if (!$this->githubAPI) {
            return true; // No podemos verificar, asumimos que existe
        }
        
        try {
            return $this->githubAPI->userExists($username);
        } catch (\Exception $e) {
            debugging('Error verificando usuario en GitHub: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return true; // En caso de error, permitimos continuar
        }
    }

    //Maneja el cambio de username (desasigna repos y limpia caché)
    private function handleUsernameChange(string $old_username, string $new_username): void {
        $this->unassignAllTeacherRepositories($old_username);
        $this->clearUsernameCache();
        $this->notifyUsernameChange($old_username, $new_username);
    }

    //Desasigna todos los repositorios asignados al profesor
    private function unassignAllTeacherRepositories(string $old_username): void {
        $my_assignments = $this->getMyAssignments();
        
        foreach ($my_assignments as $assignment) {
            $this->removeRepositoryAssignment($assignment, $old_username);
        }
    }

    //Obtiene las asignaciones del profesor actual
    private function getMyAssignments(): array {
        $all_assignments = TeacherRepoModel::getTeacherRepoAssignments($this->course->id);
        
        return array_filter($all_assignments, function($assignment) {
            return $assignment->userid == $this->user->id;
        });
    }

    //Remueve una asignación de repositorio (BD + GitHub)
    private function removeRepositoryAssignment($assignment, string $username): void {
        // Intentar remover de GitHub
        if ($this->githubAPI && !empty($username)) {
            $this->removeCollaboratorFromGithub($assignment->repo_id, $username);
        }
        TeacherRepoModel::deleteAssignment($assignment->repo_id, $this->user->id);
    }

    //Remueve al colaborador de un repositorio en GitHub
    private function removeCollaboratorFromGithub(int $repo_id, string $username): void {
        $repo = RepositoryModel::getById($repo_id);
        
        if (!$repo) {
            return;
        }
        
        try {
            $this->githubAPI->removeCollaborator($repo->nombre_repo, $username);
        } catch (\Exception $e) {
            debugging('Error removiendo colaborador: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    //Limpia la caché del username del profesor
    private function clearUsernameCache(): void {
        if ($this->cache !== null) {
            $cache_key = self::CACHE_KEY_PREFIX . $this->user->id;
            $this->cache->delete($cache_key);
        }
    }

    //Actualiza el username en caché
    private function updateUsernameCache(string $username): void {
        if ($this->cache !== null) {
            $cache_key = self::CACHE_KEY_PREFIX . $this->user->id;
            $this->cache->set($cache_key, $username);
        }
    }

    //Notifica al usuario sobre el cambio de username
    private function notifyUsernameChange(string $old_username, string $new_username): void {
        \core\notification::warning(
            "Usuario GitHub cambiado de '$old_username' a '$new_username'. " .
            "Todos los repositorios fueron desasignados. Debes reasignarlos con el nuevo usuario."
        );
    }
}

