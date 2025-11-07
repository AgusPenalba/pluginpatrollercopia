<?php
// filepath: c:\Users\usuario\Desktop\MoodleWindowsInstaller-latest\server\moodle\mod\pluginpatroller\classes\service\TeacherRepoService.php

namespace mod_pluginpatroller\service;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\TeacherRepoModel;
use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;
use mod_pluginpatroller\helpers\ConfigHelper;
use cache;

/**
 * Servicio para la gestión de repositorios asignados a profesores
 * 
 * Coordina la lógica de negocio entre el modelo de datos y GitHub API.
 * Maneja el caché del username de GitHub y las invitaciones de colaboradores.
 * 
 * @package    mod_pluginpatroller
 * @copyright  2025 Tu Institución
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class TeacherRepoService {
    
    private $course;
    private $user;
    private $githubAPI;
    private $cache;
    
    const CACHE_KEY_PREFIX = 'github_username_';
    const MAX_REPOS_PER_BATCH = 10;
    
    /**
     * Constructor del servicio
     * 
     * Inicializa el caché y la conexión con GitHub API si está configurada.
     * 
     * @param object $course Curso de Moodle
     * @param object $user Usuario/Profesor de Moodle
     */
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
        
        // Inicializar GitHub API si está configurada
        $token = ConfigHelper::getGitHubToken();
        $owner = ConfigHelper::getGitHubOwner();
        
        if ($token && $owner) {
            try {
                $this->githubAPI = new GitHubPatrollerAPI($owner, $token);
            } catch (\Exception $e) {
                debugging('Error inicializando GitHub API: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $this->githubAPI = null;
            }
        }
    }
    
    /**
     * Obtiene el username de GitHub del profesor
     * 
     * Busca primero en caché, luego en BD. Actualiza el caché si es necesario.
     * 
     * @return string Username de GitHub o string vacío si no está configurado
     */
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
    
    /**
     * Guarda el nombre de usuario GitHub del profesor
     * 
     * Si el username cambia, elimina TODAS las asignaciones existentes
     * y remueve al profesor como colaborador de GitHub.
     * 
     * @param string $github_username Nuevo username de GitHub
     * @return bool|string True si éxito, mensaje de error si falla
     */
    public function saveGithubUsername(string $github_username) {
        $github_username = trim($github_username);
        
        if (empty($github_username)) {
            return 'El nombre de usuario no puede estar vacío';
        }
        
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $github_username)) {
            return 'El nombre de usuario solo puede contener letras, números y guiones';
        }
        
        // Verificar en GitHub si la API está disponible
        if ($this->githubAPI) {
            try {
                if (!$this->githubAPI->userExists($github_username)) {
                    return "El usuario '$github_username' no existe en GitHub";
                }
            } catch (\Exception $e) {
                debugging('Error verificando usuario en GitHub: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        
        // Verificar si el username cambió
        $current_username = $this->getGithubUsername();
        $username_changed = !empty($current_username) && $current_username !== $github_username;
        
        if ($username_changed) {
            // DESASIGNAR todos los repositorios del profesor
            $all_assignments = TeacherRepoModel::getTeacherRepoAssignments($this->course->id);
            $my_assignments = array_filter($all_assignments, function($a) {
                return $a->userid == $this->user->id;
            });
            
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
            }
            
            // Limpiar caché
            if ($this->cache !== null) {
                $cache_key = self::CACHE_KEY_PREFIX . $this->user->id;
                $this->cache->delete($cache_key);
            }
            
            // Mostrar advertencia sobre la desasignación
            \core\notification::warning(
                "Usuario GitHub cambiado de '$current_username' a '$github_username'. " .
                "Todos los repositorios fueron desasignados. Debes reasignarlos con el nuevo usuario."
            );
            
            return true;
        }
        
        // Si no cambió, actualizar caché
        if ($this->cache !== null) {
            $cache_key = self::CACHE_KEY_PREFIX . $this->user->id;
            $this->cache->set($cache_key, $github_username);
        }
        
        return true;
    }
    
    /**
     * Obtiene todos los repositorios del curso clasificados por estado
     * 
     * Clasifica los repositorios en:
     * - Mis repositorios (asignados al profesor actual)
     * - Repositorios sin asignar
     * - Repositorios asignados a otros profesores
     * 
     * @return array Datos estructurados para la vista
     */
    public function getRepositoriesWithStatus(): array {
        $courseid = $this->course->id;
        $userid = $this->user->id;
        
        // Obtener username de GitHub del profesor
        $github_username = $this->getGithubUsername();
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
    
    /**
     * Asigna un repositorio al profesor actual
     * AHORA guarda el username actual en la asignación
     */
    public function assignRepository(int $repo_id): array {
        $github_username = $this->getGithubUsername();
        
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
        
        // **IMPORTANTE**: Guardar con el username actual del caché/BD
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
    
    /**
     * Desasigna un repositorio del profesor actual
     * 
     * Elimina la asignación de BD y remueve al colaborador de GitHub.
     * 
     * @param int $repo_id ID del repositorio a desasignar
     * @return array Resultado de la operación
     */
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
    
    /**
     * Sincroniza los estados de invitación con GitHub
     * 
     * Consulta el estado real en GitHub de cada repositorio asignado
     * y actualiza la BD si hubo cambios.
     * 
     * @return array Resultado con estadísticas de la sincronización
     */
    public function syncInvitationStatuses(): array {
        try {
            // 1. Verificar que el usuario tenga username de GitHub
            $github_username = $this->getGithubUsername();
            
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
                        
                        // 7. Comparar y actualizar si es necesario
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
    
    /**
     * Asigna múltiples repositorios al profesor en una sola operación
     * 
     * Procesa cada repositorio individualmente pero en una sola transacción.
     * Envía invitaciones a GitHub y actualiza los estados en BD.
     * 
     * @param array $repo_ids Array de IDs de repositorios a asignar (integers)
     * @return array Resultado con estadísticas detalladas:
     *               - success: bool
     *               - total: int (total procesados)
     *               - assigned: int (exitosos)
     *               - already_assigned: int (ya estaban asignados)
     *               - errors: int (con errores)
     *               - assigned_repos: array (detalles de asignados)
     *               - already_assigned_repos: array (detalles de duplicados)
     *               - error_repos: array (detalles de errores)
     */
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
            $github_username = $this->getGithubUsername();
            
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
                'message' => $this->generateSummaryMessage($assigned_count, $already_assigned_count, $error_count)
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
    
    /**
     * Genera un mensaje resumen de la operación de asignación múltiple
     * 
     * @param int $assigned Cantidad asignados exitosamente
     * @param int $already_assigned Cantidad que ya estaban asignados
     * @param int $errors Cantidad con errores
     * @return string Mensaje resumen
     */
    private function generateSummaryMessage(int $assigned, int $already_assigned, int $errors): string {
        $parts = [];
        
        if ($assigned > 0) {
            $parts[] = "$assigned asignado" . ($assigned != 1 ? 's' : '');
        }
        
        if ($already_assigned > 0) {
            $parts[] = "$already_assigned ya asignado" . ($already_assigned != 1 ? 's' : '');
        }
        
        if ($errors > 0) {
            $parts[] = "$errors error" . ($errors != 1 ? 'es' : '');
        }
        
        return 'Resultado: ' . implode(', ', $parts);
    }
    
    /**
     * Formatea los datos del repositorio para la vista
     */
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
    
    /**
     * Obtiene la clase CSS Bootstrap para el badge según el estado
     */
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
    
    /**
     * Obtiene el icono FontAwesome según el estado
     */
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
}