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
 * Servicio para gestión de asignaciones de repositorios a profesores:
 * - Asignar un solo repositorio a un profesor
 * - Desasignar repositorio (eliminar de BD y GitHub)
 * - Asignar múltiples repositorios en batch
 * - Enviar invitaciones de colaborador a GitHub
 * - Gestionar estados de invitación
 * - Validar duplicados y límites
 */
class TeacherRepositoryAssignmentService {
    
    private $course;
    private $user;
    private $githubAPI;
    private $cache;
    private $profileService;
    private $formatterService;
    
    const MAX_REPOS_PER_BATCH = 10;
    
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
    
    // Asigna un repositorio al profesor actual
    public function assignRepository(int $repo_id): array {
        $github_username = $this->profileService->getGithubUsername();
        
        if (empty($github_username)) {
            return [
                'success' => false,
                'message' => 'Debe configurar su nombre de usuario de GitHub primero',
                'status' => GitHubRegistrationStatus::MISSING_USERNAME
            ];
        }
        
        $repo = RepositoryModel::getById($repo_id);
        if (!$repo) {
            return [
                'success' => false,
                'message' => 'El repositorio no existe',
                'status' => GitHubRegistrationStatus::ERROR
            ];
        }
        
        $existing = TeacherRepoModel::getAssignment($repo_id, $this->user->id);
        if ($existing) {
            return [
                'success' => false,
                'message' => 'Este repositorio ya está asignado a usted',
                'status' => $existing->invitacion_status
            ];
        }
        
        //Guardar con el username actual del caché/BD
        $assignment_id = TeacherRepoModel::createAssignment(
            $repo_id,
            $this->user->id,
            $github_username  // Username del momento de la asignación
        );
        
        if (!$assignment_id) {
            return [
                'success' => false,
                'message' => 'Error al crear la asignación en la base de datos',
                'status' => GitHubRegistrationStatus::ERROR
            ];
        }
        
        if ($this->githubAPI) {
            $old_error_reporting = error_reporting(E_ERROR | E_PARSE);
            
            try {
                $result = $this->githubAPI->inviteCollaborator($repo->nombre_repo, $github_username);
                error_reporting($old_error_reporting);
                
                if ($result) {
                    TeacherRepoModel::updateInvitationStatus(
                        $repo_id,
                        $this->user->id,
                        GitHubRegistrationStatus::INVITATION_SENT
                    );
                    
                    return [
                        'success' => true,
                        'message' => "Repositorio asignado. Invitación enviada a '$github_username' en GitHub.",
                        'status' => GitHubRegistrationStatus::INVITATION_SENT
                    ];
                } else {
                    TeacherRepoModel::updateInvitationStatus(
                        $repo_id,
                        $this->user->id,
                        GitHubRegistrationStatus::ERROR
                    );
                    
                    return [
                        'success' => false,
                        'message' => 'Error al enviar la invitación en GitHub',
                        'status' => GitHubRegistrationStatus::ERROR
                    ];
                }
                
            } catch (\Exception $e) {
                error_reporting($old_error_reporting);
                
                TeacherRepoModel::updateInvitationStatus(
                    $repo_id,
                    $this->user->id,
                    GitHubRegistrationStatus::ERROR
                );
                
                return [
                    'success' => false,
                    'message' => 'Error al comunicarse con GitHub: ' . $e->getMessage(),
                    'status' => GitHubRegistrationStatus::ERROR
                ];
            }
        }
        
        return [
            'success' => true,
            'message' => 'Repositorio asignado (API de GitHub no configurada)',
            'status' => GitHubRegistrationStatus::UNPROCESSED
        ];
    }
    
    //Desasigna un repositorio del profesor actual

    public function unassignRepository(int $repo_id): array {
        $assignment = TeacherRepoModel::getAssignment($repo_id, $this->user->id);
        
        if (!$assignment) {
            return [
                'success' => false,
                'message' => 'Este repositorio no está asignado a usted'
            ];
        }
        
        $repo = RepositoryModel::getById($repo_id);
        if (!$repo) {
            return [
                'success' => false,
                'message' => 'El repositorio no existe'
            ];
        }
        
        // Intentar remover de GitHub
        $github_username = $assignment->github_username;
        $removed_from_github = false;
        
        if ($this->githubAPI && !empty($github_username)) {
            try {
                $removed_from_github = $this->githubAPI->removeCollaborator(
                    $repo->nombre_repo,
                    $github_username
                );
            } catch (\Exception $e) {
                // Continuar aunque falle la remoción
            }
        }
        
        $result = TeacherRepoModel::deleteAssignment($repo_id, $this->user->id);
        
        if ($result) {
            $message = 'Repositorio desasignado correctamente';
            if ($removed_from_github) {
                $message .= ' y acceso eliminado de GitHub';
            }
            
            return [
                'success' => true,
                'message' => $message
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Error al eliminar la asignación'
        ];
    }
    
    //Asigna múltiples repositorios al profesor en una sola operación
    public function assignMultipleRepositories(array $repo_ids): array {
        try {
            // Validación: array vacío
            if (empty($repo_ids)) {
                return [
                    'success' => false,
                    'message' => 'No se proporcionaron repositorios para asignar',
                    'total' => 0,
                    'assigned' => 0,
                    'already_assigned' => 0,
                    'errors' => 0,
                    'assigned_repos' => [],
                    'already_assigned_repos' => [],
                    'error_repos' => []
                ];
            }
            
            // Validación: límite máximo
            if (count($repo_ids) > self::MAX_REPOS_PER_BATCH) {
                return [
                    'success' => false,
                    'message' => "Máximo " . self::MAX_REPOS_PER_BATCH . " repositorios por operación",
                    'total' => count($repo_ids),
                    'assigned' => 0,
                    'already_assigned' => 0,
                    'errors' => count($repo_ids),
                    'assigned_repos' => [],
                    'already_assigned_repos' => [],
                    'error_repos' => []
                ];
            }
            
            // Validación: username configurado
            $github_username = $this->profileService->getGithubUsername();
            
            if (empty($github_username)) {
                return [
                    'success' => false,
                    'message' => 'Debe configurar su nombre de usuario de GitHub primero',
                    'total' => count($repo_ids),
                    'assigned' => 0,
                    'already_assigned' => 0,
                    'errors' => count($repo_ids),
                    'assigned_repos' => [],
                    'already_assigned_repos' => [],
                    'error_repos' => []
                ];
            }
            
            // Inicializar contadores
            $total = count($repo_ids);
            $assigned_count = 0;
            $already_assigned_count = 0;
            $error_count = 0;
            
            $assigned_repos = [];
            $already_assigned_repos = [];
            $error_repos = [];
            
            // Procesar cada repositorio
            foreach ($repo_ids as $repo_id) {
                try {
                    // Validar que sea un ID numérico válido
                    if (!is_numeric($repo_id) || $repo_id <= 0) {
                        $error_count++;
                        $error_repos[] = [
                            'repo_id' => $repo_id,
                            'repo_name' => "ID inválido: $repo_id",
                            'error' => 'ID de repositorio inválido'
                        ];
                        continue;
                    }
                    
                    // Obtener el repositorio
                    $repo = RepositoryModel::getById($repo_id);
                    
                    if (!$repo) {
                        $error_count++;
                        $error_repos[] = [
                            'repo_id' => $repo_id,
                            'repo_name' => "ID: $repo_id",
                            'error' => 'Repositorio no encontrado'
                        ];
                        continue;
                    }
                    
                    // Verificar si ya está asignado
                    $existing = TeacherRepoModel::getAssignment($repo_id, $this->user->id);
                    
                    if ($existing) {
                        $already_assigned_count++;
                        $already_assigned_repos[] = [
                            'repo_id' => $repo->id,
                            'repo_name' => $repo->nombre_repo,
                            'status' => $existing->invitacion_status,
                            'status_text' => GitHubRegistrationStatus::getShortText($existing->invitacion_status)
                        ];
                        continue;
                    }
                    
                    // Crear la asignación en la BD
                    $assignment_id = TeacherRepoModel::createAssignment(
                        $repo_id,
                        $this->user->id,
                        $github_username
                    );
                    
                    if (!$assignment_id) {
                        $error_count++;
                        $error_repos[] = [
                            'repo_id' => $repo->id,
                            'repo_name' => $repo->nombre_repo,
                            'error' => 'Error al crear la asignación en la base de datos'
                        ];
                        continue;
                    }
                    
                    // Intentar enviar invitación en GitHub
                    $invitation_status = GitHubRegistrationStatus::UNPROCESSED;
                    $invitation_message = 'Asignado (pendiente de procesar)';
                    
                    if ($this->githubAPI) {
                        try {
                            $result = $this->githubAPI->inviteCollaborator(
                                $repo->nombre_repo,
                                $github_username
                            );
                            
                            if ($result) {
                                $invitation_status = GitHubRegistrationStatus::INVITATION_SENT;
                                $invitation_message = "Invitación enviada a '$github_username'";
                            } else {
                                $invitation_status = GitHubRegistrationStatus::ERROR;
                                $invitation_message = 'Error al enviar la invitación en GitHub';
                            }
                            
                            // Actualizar el estado en la BD
                            TeacherRepoModel::updateInvitationStatus(
                                $repo_id,
                                $this->user->id,
                                $invitation_status
                            );
                            
                        } catch (\Exception $e) {
                            $invitation_status = GitHubRegistrationStatus::ERROR;
                            $invitation_message = 'Error de GitHub: ' . $e->getMessage();
                            
                            TeacherRepoModel::updateInvitationStatus(
                                $repo_id,
                                $this->user->id,
                                $invitation_status
                            );
                        }
                    } else {
                        $invitation_message = 'Asignado (GitHub API no disponible)';
                    }
                    
                    // Registrar éxito
                    $assigned_count++;
                    $assigned_repos[] = [
                        'repo_id' => $repo->id,
                        'repo_name' => $repo->nombre_repo,
                        'status' => $invitation_status,
                        'status_text' => GitHubRegistrationStatus::getShortText($invitation_status),
                        'message' => $invitation_message
                    ];
                    
                } catch (\Exception $e) {
                    $error_count++;
                    $repo_name = isset($repo) ? $repo->nombre_repo : "ID: $repo_id";
                    $error_repos[] = [
                        'repo_id' => $repo_id,
                        'repo_name' => $repo_name,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Invalidar caché si hubo cambios exitosos
            if ($assigned_count > 0 && $this->cache) {
                $cache_key = "teacher_repos_{$this->user->id}_{$this->course->id}";
                $this->cache->delete($cache_key);
            }
            
            // Retornar resultado completo
            return [
                'success' => true,
                'total' => $total,
                'assigned' => $assigned_count,
                'already_assigned' => $already_assigned_count,
                'errors' => $error_count,
                'assigned_repos' => $assigned_repos,
                'already_assigned_repos' => $already_assigned_repos,
                'error_repos' => $error_repos,
                'message' => $this->formatterService->generateSummaryMessage($assigned_count, $already_assigned_count, $error_count)
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error crítico al asignar repositorios: ' . $e->getMessage(),
                'total' => count($repo_ids),
                'assigned' => 0,
                'already_assigned' => 0,
                'errors' => count($repo_ids),
                'assigned_repos' => [],
                'already_assigned_repos' => [],
                'error_repos' => []
            ];
        }
    }
}
