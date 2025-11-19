<?php
// filepath: c:\Users\rochi\Documents\ORT\Ultimo cuatri\PRF-2025C2-YA-B-3\classes\service\view\TeacherRepoService.php

namespace mod_pluginpatroller\service\view;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\TeacherRepoModel;
use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\helpers\ConfigHelper;
use cache;

//Servicio para la gestión del username de GitHub de profesores
class TeacherRepoService {
    
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
            $apiService = new \mod_pluginpatroller\service\github\GitHubApiService();
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
    
    // Guarda el nombre de usuario GitHub del profesor
    public function saveGithubUsername(string $github_username): array {
        $github_username = trim($github_username);
        
        if (empty($github_username)) {
            return [
                'success' => false,
                'message' => 'El nombre de usuario no puede estar vacío'
            ];
        }
        
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $github_username)) {
            return [
                'success' => false,
                'message' => 'El nombre de usuario solo puede contener letras, números y guiones'
            ];
        }
        
        // Verificar en GitHub si la API está disponible
        if ($this->githubAPI) {
            try {
                if (!$this->githubAPI->userExists($github_username)) {
                    return [
                        'success' => false,
                        'message' => "El usuario '$github_username' no existe en GitHub"
                    ];
                }
            } catch (\Exception $e) {
                debugging('Error verificando usuario en GitHub: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        
        // Verificar si el username cambió
        $current_username = $this->getGithubUsername();
        $username_changed = !empty($current_username) && $current_username !== $github_username;
        $is_first_time = empty($current_username);
        
        if ($username_changed) {
            // DESASIGNAR todos los repositorios del profesor
            $all_assignments = TeacherRepoModel::getTeacherRepoAssignments($this->course->id);
            $my_assignments = array_filter($all_assignments, function($a) {
                return $a->userid == $this->user->id;
            });
            
            $unassigned_count = 0;
            foreach ($my_assignments as $assignment) {
                // Intentar remover de GitHub
                if ($this->githubAPI && !empty($current_username)) {
                    $repo = RepositoryModel::getById($assignment->repo_id);
                    if ($repo) {
                        try {
                            $this->githubAPI->removeCollaborator($repo->nombre_repo, $current_username);
                        } catch (\Exception $e) {
                            // Continuar aunque falle la remoción
                            debugging('Error removiendo colaborador: ' . $e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                }
                
                // Eliminar asignación de BD
                TeacherRepoModel::deleteAssignment($assignment->repo_id, $this->user->id);
                $unassigned_count++;
            }
            
            // Limpiar caché
            if ($this->cache !== null) {
                $cache_key = self::CACHE_KEY_PREFIX . $this->user->id;
                $this->cache->delete($cache_key);
            }
            
            // Guardar nuevo username en BD
            $saved = TeacherRepoModel::saveTeacherGithubUsername($this->user->id, $github_username);
            
            if (!$saved) {
                return [
                    'success' => false,
                    'username_changed' => true,
                    'message' => 'Repositorios desasignados, pero error al guardar el nuevo usuario GitHub'
                ];
            }
            
            return [
                'success' => true,
                'username_changed' => true,
                'old_username' => $current_username,
                'new_username' => $github_username,
                'unassigned_repos' => $unassigned_count,
                'message' => "Usuario GitHub cambiado de '$current_username' a '$github_username'. " .
                            "Todos los repositorios ($unassigned_count) fueron desasignados."
            ];
        }
        
        // Si no cambió, actualizar caché Y base de datos
        if ($this->cache !== null) {
            $cache_key = self::CACHE_KEY_PREFIX . $this->user->id;
            $this->cache->set($cache_key, $github_username);
        }
        
        // Guardar en base de datos
        $saved = \mod_pluginpatroller\model\TeacherRepoModel::saveTeacherGithubUsername(
            $this->user->id, 
            $github_username
        );
        
        if (!$saved) {
            return [
                'success' => false,
                'username_changed' => false,
                'message' => 'Error al guardar el usuario GitHub en la base de datos'
            ];
        }
        
        return [
            'success' => true,
            'username_changed' => false,
            'is_first_time' => $is_first_time,
            'username' => $github_username,
            'message' => $is_first_time 
                ? "Usuario GitHub '$github_username' configurado correctamente"
                : "Usuario GitHub confirmado: '$github_username'"
        ];
    }
}
