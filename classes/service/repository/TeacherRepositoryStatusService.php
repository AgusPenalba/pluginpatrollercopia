<?php
namespace mod_pluginpatroller\service\repository;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\TeacherRepoModel;
use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;
use mod_pluginpatroller\service\github\TeacherGitHubProfileService;
use mod_pluginpatroller\service\repository\RepositoryFormatterService;
use cache;

/**
 * Servicio para gestión de estados de repositorios de profesores:
 * - Obtener repositorios clasificados por estado (asignados/sin asignar/de otros)
 * - Sincronizar estados de invitación con GitHub
 * - Actualizar estados en BD basándose en estado real de GitHub
 * - Gestionar caché de estados*/
class TeacherRepositoryStatusService {
    
    private $course;
    private $user;
    private $githubAPI;
    private $cache;
    private $profileService;
    private $formatterService;

    public function __construct($course, $user, $githubAPI = null) {
        $this->course = $course;
        $this->user = $user;
        $this->githubAPI = $githubAPI;
        
        // Inicializar caché
        try {
            $this->cache = cache::make('mod_pluginpatroller', 'teacher_github_usernames');
        } catch (\Exception $e) {
            debugging('Error inicializando caché: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->cache = null;
        }
        
        // Inicializar servicios auxiliares
        $this->profileService = new TeacherGitHubProfileService($course, $user);
        $this->formatterService = new RepositoryFormatterService();
    }
    
    //Obtiene los repositorios del curso clasificados por estado

    public function getRepositoriesWithStatus(): array {
        $courseid = $this->course->id;
        $userid = $this->user->id;
        
        // Obtener username de GitHub del profesor
        $github_username = $this->profileService->getGithubUsername();
        $has_valid_username = !empty($github_username);
        
        // Obtener todos los repositorios del curso
        $all_repos = TeacherRepoModel::getAllByCourseId($courseid);
        
        if (empty($all_repos)) {
            return [
                'has_repos' => false,
                'my_repos' => [],
                'my_repos_count' => 0,
                'unassigned_repos' => [],
                'other_teachers_repos' => [],
                'error_message' => 'No hay repositorios disponibles en este curso.',
                'github_username' => $github_username,
                'has_valid_username' => $has_valid_username,
                'can_assign' => false
            ];
        }
        
        // Obtener asignaciones existentes
        $teacher_assignments = TeacherRepoModel::getTeacherRepoAssignments($courseid);
        
        // Clasificar repositorios
        $my_repos = [];
        $unassigned_repos = [];
        $other_teachers_repos = [];
        
        // Crear índice de asignaciones por repo_id
        $assignments_by_repo = [];
        foreach ($teacher_assignments as $assignment) {
            if (!isset($assignments_by_repo[$assignment->repo_id])) {
                $assignments_by_repo[$assignment->repo_id] = [];
            }
            $assignments_by_repo[$assignment->repo_id][] = $assignment;
        }
        
        // Procesar cada repositorio
        foreach ($all_repos as $repo) {
            // Repositorio no asignado
            if (!isset($assignments_by_repo[$repo->id])) {
                $unassigned_repos[] = $this->formatterService->formatRepoData($repo, GitHubRegistrationStatus::UNPROCESSED);
                continue;
            }
            
            // Verificar si está asignado al profesor actual
            $is_my_repo = false;
            foreach ($assignments_by_repo[$repo->id] as $assignment) {
                if ($assignment->userid == $userid) {
                    $is_my_repo = true;
                    $status = $assignment->invitacion_status ?? GitHubRegistrationStatus::UNPROCESSED;
                    $my_repos[] = $this->formatterService->formatRepoData($repo, $status);
                    break;
                }
            }
            
            // Si no es mío, está asignado a otros
            if (!$is_my_repo) {
                $other_teachers_count = count($assignments_by_repo[$repo->id]);
                $other_teachers_repos[] = [
                    'id' => $repo->id,
                    'nombre' => $repo->nombre_repo,
                    'sede' => $repo->sede ?? '',
                    'curso' => $repo->curso ?? '',
                    'grupo' => $repo->num_grupo ?? '',
                    'num_profesores' => $other_teachers_count,
                    'mensaje' => "Asignado a {$other_teachers_count} " . 
                                ($other_teachers_count == 1 ? 'profesor' : 'profesores'),
                    'status_class' => 'badge-info',
                    'status_icon' => '<i class="fa fa-user-lock"></i>'
                ];
            }
        }
        
        return [
            'has_repos' => true,
            'my_repos' => $my_repos,
            'my_repos_count' => count($my_repos),
            'unassigned_repos' => $unassigned_repos,
            'other_teachers_repos' => $other_teachers_repos,
            'github_username' => $github_username,
            'has_valid_username' => $has_valid_username,
            'can_assign' => $has_valid_username && !empty($unassigned_repos)
        ];
    }
    
    //Sincroniza los estados de invitación de todos los repositorios asignados al profesor
    public function syncInvitationStatuses(): array {
        try {
            // Verificar que el usuario tenga username de GitHub
            $github_username = $this->profileService->getGithubUsername();
            
            if (empty($github_username)) {
                return [
                    'success' => false,
                    'message' => 'Debe configurar su nombre de usuario de GitHub primero',
                    'total_repos' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'errors' => 0
                ];
            }
            
            //Obtener todas las asignaciones del curso y filtrar
            $all_assignments = TeacherRepoModel::getTeacherRepoAssignments($this->course->id);
            
            // Filtrar solo las del profesor actual
            $assignments = array_filter($all_assignments, function($assignment) {
                return $assignment->userid == $this->user->id;
            });
            
            $total_repos = count($assignments);
            
            if ($total_repos === 0) {
                return [
                    'success' => true,
                    'message' => 'No hay repositorios asignados para sincronizar',
                    'total_repos' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'errors' => 0,
                    'updated_repos' => [],
                    'error_repos' => []
                ];
            }
            
            //Verificar que GitHub API esté disponible
            if (!$this->githubAPI) {
                return [
                    'success' => false,
                    'message' => 'API de GitHub no configurada',
                    'total_repos' => $total_repos,
                    'updated' => 0,
                    'unchanged' => 0,
                    'errors' => $total_repos
                ];
            }
            
            //Inicializar contadores y arrays de resultados
            $updated_count = 0;
            $unchanged_count = 0;
            $error_count = 0;
            $updated_repos = [];
            $error_repos = [];
            
            //Procesar cada repositorio
            foreach ($assignments as $assignment) {
                try {
                    // Obtener información del repositorio
                    $repo = RepositoryModel::getById($assignment->repo_id);
                    
                    if (!$repo) {
                        $error_count++;
                        $error_repos[] = [
                            'repo_id' => $assignment->repo_id,
                            'repo_name' => "ID: {$assignment->repo_id}",
                            'error' => 'Repositorio no encontrado'
                        ];
                        continue;
                    }
                    
                    //Consultar estado real en GitHub
                    $old_error_reporting = error_reporting(E_ERROR | E_PARSE);
                    
                    try {
                        $github_status = $this->githubAPI->checkCollaboratorStatus(
                            $repo->nombre_repo,
                            $github_username
                        );
                        error_reporting($old_error_reporting);
                        
                        //Comparar y actualizar si es necesario
                        if ($github_status !== (int)$assignment->invitacion_status) {
                            // Actualizar en la base de datos
                            $update_result = TeacherRepoModel::updateInvitationStatus(
                                $assignment->repo_id,
                                $this->user->id,
                                $github_status
                            );
                            
                            if ($update_result) {
                                $updated_count++;
                                
                                $updated_repos[] = [
                                    'repo_id' => $repo->id,
                                    'repo_name' => $repo->nombre_repo,
                                    'old_status' => $assignment->invitacion_status,
                                    'new_status' => $github_status,
                                    'old_status_text' => GitHubRegistrationStatus::getShortText($assignment->invitacion_status),
                                    'new_status_text' => GitHubRegistrationStatus::getShortText($github_status)
                                ];
                                
                                if ($this->cache) {
                                    $cache_key = "teacher_repos_{$this->user->id}_{$this->course->id}";
                                    $this->cache->delete($cache_key);
                                }
                            } else {
                                $error_count++;
                                $error_repos[] = [
                                    'repo_id' => $repo->id,
                                    'repo_name' => $repo->nombre_repo,
                                    'error' => 'Error al actualizar en la base de datos'
                                ];
                            }
                        } else {
                            $unchanged_count++;
                        }
                    } catch (\Exception $e) {
                        $error_count++;
                        $error_repos[] = [
                            'repo_id' => $repo->id,
                            'repo_name' => $repo->nombre_repo,
                            'error' => 'Error de GitHub: ' . $e->getMessage()
                        ];
                    }
                } catch (\Exception $e) {
                    $error_count++;
                    $repo_name = isset($repo) ? $repo->nombre_repo : "ID: {$assignment->repo_id}";
                    $error_repos[] = [
                        'repo_id' => $assignment->repo_id,
                        'repo_name' => $repo_name,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return [
                'success' => true,
                'total_repos' => $total_repos,
                'updated' => $updated_count,
                'unchanged' => $unchanged_count,
                'errors' => $error_count,
                'updated_repos' => $updated_repos,
                'error_repos' => $error_repos
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al sincronizar: ' . $e->getMessage(),
                'total_repos' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'errors' => 1,
                'updated_repos' => [],
                'error_repos' => []
            ];
        }
    }
}
