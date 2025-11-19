<?php
// filepath: c:\Users\rochi\Documents\ORT\Ultimo cuatri\PRF-2025C2-YA-B-3\classes\service\view\TeacherRepoStatusService.php

namespace mod_pluginpatroller\service\view;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\TeacherRepoModel;
use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;
use cache;

//Servicio para la gestión de estados y sincronización de repositorios de profesores
class TeacherRepoStatusService {
    
    private $course;
    private $user;
    private $githubAPI;
    private $cache;

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
    }
    
    //Obtiene todos los repositorios del curso clasificados por estado
    public function getRepositoriesWithStatus(string $github_username): array {
        $courseid = $this->course->id;
        $userid = $this->user->id;
        
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
                $unassigned_repos[] = $this->formatRepoData($repo, GitHubRegistrationStatus::UNPROCESSED);
                continue;
            }
            
            // Verificar si está asignado al profesor actual
            $is_my_repo = false;
            foreach ($assignments_by_repo[$repo->id] as $assignment) {
                if ($assignment->userid == $userid) {
                    $is_my_repo = true;
                    $status = $assignment->invitacion_status ?? GitHubRegistrationStatus::UNPROCESSED;
                    $my_repos[] = $this->formatRepoData($repo, $status);
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
    
    //Sincroniza los estados de invitación con GitHub
    public function syncInvitationStatuses(string $github_username): array {
        try {
            // 1. Verificar que el usuario tenga username de GitHub
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
            
            // 2. Obtener todos los repositorios asignados al profesor
            // CORREGIDO: Obtener todas las asignaciones del curso y filtrar
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
            
            // 3. Verificar que GitHub API esté disponible
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
            
            // 4. Inicializar contadores y arrays de resultados
            $updated_count = 0;
            $unchanged_count = 0;
            $error_count = 0;
            $updated_repos = [];
            $error_repos = [];
            
            // 5. Procesar cada repositorio
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
                    
                    // 6. Consultar estado real en GitHub
                    $old_error_reporting = error_reporting(E_ERROR | E_PARSE);
                    
                    try {
                        $github_status = $this->githubAPI->checkCollaboratorStatus(
                            $repo->nombre_repo,
                            $github_username
                        );
                        error_reporting($old_error_reporting);
                        
                        // 7. Detectar si la invitación fue rechazada y reenviar automáticamente
                        $old_status = (int)$assignment->invitacion_status;
                        $was_invitation_active = GitHubRegistrationStatus::isInvitationActive($old_status);
                        $is_now_unprocessed = ($github_status === GitHubRegistrationStatus::UNPROCESSED);
                        
                        if ($was_invitation_active && $is_now_unprocessed) {
                            // La invitación fue rechazada - reenviar automáticamente
                            $resend_result = $this->githubAPI->inviteStudentToRepo(
                                $repo->nombre_repo,
                                $github_username
                            );
                            
                            $new_status = GitHubRegistrationStatus::fromHttpCode($resend_result['httpCode']);
                            
                            TeacherRepoModel::updateInvitationStatus(
                                $assignment->repo_id,
                                $this->user->id,
                                $new_status
                            );
                            
                            $updated_count++;
                            $updated_repos[] = [
                                'repo_id' => $repo->id,
                                'repo_name' => $repo->nombre_repo,
                                'old_status' => $old_status,
                                'new_status' => $new_status,
                                'old_status_text' => GitHubRegistrationStatus::getShortText($old_status),
                                'new_status_text' => GitHubRegistrationStatus::getShortText($new_status),
                                'auto_resent' => true
                            ];
                            
                            if ($this->cache) {
                                $cache_key = "teacher_repos_{$this->user->id}_{$this->course->id}";
                                $this->cache->delete($cache_key);
                            }
                            continue;
                        }
                        
                        // 8. Comparar y actualizar si es necesario (cambios normales)
                        if ($github_status !== $old_status) {
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
                                    'old_status' => $old_status,
                                    'new_status' => $github_status,
                                    'old_status_text' => GitHubRegistrationStatus::getShortText($old_status),
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
    
    // Prepara todos los datos necesarios para renderizar la vista
    public function prepareViewData(array $raw_data, $cm, $templateDataService): array {
        $data = $raw_data;
        
        // Configuración de filtros
        $filter_config = [
            'show_name' => true,
            'show_sede' => true,
            'show_curso' => true,
            'show_repo' => false
        ];
        
        // Preparar filtros usando el servicio
        $filters_data = $templateDataService->prepareFiltersConfig($filter_config);
        
        // Preparar ID único para la tabla
        $table_id = 'unassigned_repos_table_' . $cm->id;
        
        // Añadir información adicional para la vista
        $data['courseid'] = $this->course->id;
        $data['cmid'] = $cm->id;
        $data['sesskey'] = sesskey();
        
        // Añadir configuración de filtros
        $data['filters_config'] = true;
        $data['filters'] = is_array($filters_data) && isset($filters_data['filters']) ? $filters_data['filters'] : [];
        $data['filter_script'] = is_array($filters_data) && isset($filters_data['script_functions']) ? $filters_data['script_functions'] : '';
        $data['table_id'] = $table_id;
        
        // Convertir valores booleanos para Mustache
        $data['has_repos'] = !empty($data['has_repos']) ? true : false;
        $data['has_valid_username'] = !empty($data['has_valid_username']) ? true : false;
        $data['can_assign'] = !empty($data['can_assign']) ? true : false;
        $data['has_deleted_repos'] = !empty($data['has_deleted_repos']) ? true : false;
        
        // Asegurar que los arrays existen
        $data['my_repos'] = $data['my_repos'] ?? [];
        $data['unassigned_repos'] = $data['unassigned_repos'] ?? [];
        $data['other_teachers_repos'] = $data['other_teachers_repos'] ?? [];
        $data['deleted_repos'] = $data['deleted_repos'] ?? [];
        
        // Añadir atributos data-* a los repos no asignados para filtrado
        foreach ($data['unassigned_repos'] as &$repo) {
            $repo['data_sede'] = $repo['sede'] ?? '';
            $repo['data_curso'] = $repo['curso'] ?? '';
        }
        unset($repo);
        
        // Añadir banderas para Mustache
        $data['has_my_repos'] = !empty($data['my_repos']);
        $data['has_unassigned_repos'] = !empty($data['unassigned_repos']);
        $data['has_other_teachers_repos'] = !empty($data['other_teachers_repos']);
        
        return $data;
    }
    
    //Formatea los datos del repositorio para la vista
    private function formatRepoData($repo, int $status): array {
        return [
            'id' => $repo->id,
            'nombre' => $repo->nombre_repo,
            'sede' => $repo->sede ?? '',
            'curso' => $repo->curso ?? '',
            'grupo' => $repo->num_grupo ?? '',
            'status' => $status,
            'status_text' => GitHubRegistrationStatus::getShortText($status),
            'status_class' => $this->getStatusBadgeClass($status),
            'status_icon' => $this->getStatusIcon($status),
            'can_unassign' => true
        ];
    }
    
    //Obtiene la clase CSS Bootstrap para el badge según el estado
    private function getStatusBadgeClass(int $status): string {
        switch ($status) {
            case GitHubRegistrationStatus::UNPROCESSED:
                return 'badge-secondary';
            case GitHubRegistrationStatus::MISSING_USERNAME:
            case GitHubRegistrationStatus::USERNAME_NOT_FOUND:
            case GitHubRegistrationStatus::ERROR:
                return 'badge-danger';
            case GitHubRegistrationStatus::INVITATION_SENT:
                return 'badge-info';
            case GitHubRegistrationStatus::INVITATION_PENDING:
                return 'badge-warning';
            case GitHubRegistrationStatus::ACCEPTED:
                return 'badge-success';
            default:
                return 'badge-secondary';
        }
    }
    
    //Obtiene el icono FontAwesome según el estado
    private function getStatusIcon(int $status): string {
        switch ($status) {
            case GitHubRegistrationStatus::UNPROCESSED:
                return '<i class="fa fa-hourglass-start"></i>';
            case GitHubRegistrationStatus::MISSING_USERNAME:
                return '<i class="fa fa-user-times"></i>';
            case GitHubRegistrationStatus::USERNAME_NOT_FOUND:
                return '<i class="fa fa-exclamation-triangle"></i>';
            case GitHubRegistrationStatus::INVITATION_SENT:
                return '<i class="fa fa-paper-plane"></i>';
            case GitHubRegistrationStatus::INVITATION_PENDING:
                return '<i class="fa fa-clock-o"></i>';
            case GitHubRegistrationStatus::ACCEPTED:
                return '<i class="fa fa-check-circle"></i>';
            case GitHubRegistrationStatus::ERROR:
                return '<i class="fa fa-times-circle"></i>';
            default:
                return '<i class="fa fa-question-circle"></i>';
        }
    }
    
    //Muestra notificaciones del resultado de sincronización
    public function notifySyncResult(array $result): void {
        if (!$result['success']) {
            \core\notification::error(
                $result['message'] ?? 'Error al sincronizar invitaciones'
            );
            return;
        }
        
        // Mensaje principal de éxito
        $message = sprintf(
            'Sincronización completada: %d repositorios procesados, %d actualizados, %d sin cambios',
            $result['total_repos'],
            $result['updated'],
            $result['unchanged']
        );
        
        \core\notification::success($message);
        
        // Mostrar detalles de repositorios actualizados
        if (!empty($result['updated_repos'])) {
            foreach ($result['updated_repos'] as $update) {
                \core\notification::info(
                    "{$update['repo_name']}: {$update['old_status_text']} → {$update['new_status_text']}"
                );
            }
        }
        
        // Mostrar errores si los hay
        if ($result['errors'] > 0 && !empty($result['error_repos'])) {
            foreach ($result['error_repos'] as $error) {
                \core\notification::warning(
                    "Error en {$error['repo_name']}: {$error['error']}"
                );
            }
        }
    }
}
