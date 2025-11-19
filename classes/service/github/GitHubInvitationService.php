<?php
namespace mod_pluginpatroller\service\github;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;

/**
 * Servicio para gestión de invitaciones de GitHub:
 * - Procesar invitaciones a repositorios (masivas e individuales)
 * - Enviar invitaciones a través de la API de GitHub
 * - Cancelar invitaciones pendientes
 * - Mapear estados entre GitHub y la base de datos
 */
class GitHubInvitationService {
    
    private $course;
    private $githubAPI;

    public function __construct($course) {
        $this->course = $course;
        $api_service = new GitHubApiService();
        $this->githubAPI = $api_service->getAPI();
    }
    
    //Procesa invitaciones para un repositorio o todos los repositorios
    public function processInvitations(string $repository_selected, bool $force_resend = false): string {
        // Verificaciones detalladas de configuración
        if (!$this->githubAPI) {
            // API no configurada
            throw new \Exception('API de GitHub no configurada - Verifica token y owner en configuración');
        }

        // Probar conectividad básica verificando si podemos hacer una consulta simple
        try {
            $test_user = $this->githubAPI->userExists('github'); // GitHub siempre existe
            
        } catch (\Exception $conn_error) {
            throw new \Exception('Error de conectividad GitHub: ' . $conn_error->getMessage());
        }
        
        $repositories = RepositoryModel::getAllByCourseId($this->course->id);
        
        if ($repository_selected === 'All') {
            $repo_list = $repositories;
        } else {
            // Buscar el repositorio por nombre (valor) en lugar de por clave
            $repo_id = array_search($repository_selected, $repositories);
            if ($repo_id === false) {
                throw new \Exception('Repositorio no encontrado');
            }
            $repo_list = [$repo_id => $repository_selected];
        }
        
        try {
            $result = $this->inviteStudentsByRepoNameList($repo_list, $force_resend);    
            return 'Invitaciones procesadas exitosamente.<br>' . $result;
        } catch (\Exception $e) {
            throw new \Exception('Error procesando invitaciones: ' . $e->getMessage());
        }
    }
    
    //Invita estudiantes a una lista de repositorios
    private function inviteStudentsByRepoNameList(array $repo_list, bool $force_resend = false): string {
        global $DB;
                
        $results = [];
        $total_processed = 0;
        $total_errors = 0;
        
        foreach ($repo_list as $repo_name) {
            // Obtener estudiantes asignados a este repositorio
            $repo_record = $DB->get_record('repositorios_data_patroller', [
                'nombre_repo' => $repo_name,
                'id_materia' => $this->course->id
            ]);
            
            if (!$repo_record) {
                $results[] = "Repositorio '$repo_name': No encontrado en BD";
                continue;
            }
                        
            // Buscar estudiantes asignados a este repositorio
            $students = $DB->get_records('usuarios_data_patroller', [
                'id_repo' => $repo_record->id,
                'id_materia' => $this->course->id
            ]);
                        
            if (empty($students)) {
                $results[] = "Repositorio '$repo_name': Sin estudiantes asignados";
                continue;
            }
            
            foreach ($students as $student) {
                if (empty($student->usuario_github)) {
                    $results[] = "Estudiante {$student->id}: Sin usuario GitHub configurado";
                    continue;
                }
                
                // Solo bloquear estado aceptado (colaboradores activos)
                if ($student->invitacion_status == 3) {
                    $results[] = "Estudiante '{$student->usuario_github}': Ya es colaborador activo";
                    continue;
                }
                
                $total_processed++;
                try {
                    $this->sendGitHubInvitation($student, $repo_record);
                    $results[] = "Invitación procesada para '{$student->usuario_github}' en '{$repo_name}'";
                } catch (\Exception $e) {
                    $total_errors++;
                    $results[] = "Error con '{$student->usuario_github}' en '{$repo_name}': " . $e->getMessage();
                }
            }
        }
        
        $summary = "Resumen: $total_processed estudiantes procesados";
        if ($total_errors > 0) {
            $summary .= ", $total_errors errores";
        }
        
        return $summary . " | " . implode(" | ", $results);
    }
    
    //Envía una invitación de GitHub a un estudiante
    private function sendGitHubInvitation($student, $repo_record): void {
        global $DB;
                
        try {
            if (!$this->githubAPI) {
                throw new \Exception('API de GitHub no configurada - verifica token y owner');
            }
            
            $current_status = (int)($student->invitacion_status ?? GitHubRegistrationStatus::UNPROCESSED);
            
            // Si ya está aceptado, no hacer nada
            if ($current_status === GitHubRegistrationStatus::ACCEPTED) {
                error_log("PLUGIN DEBUG: Estudiante {$student->id} ya es colaborador en {$repo_record->nombre_repo}");
                return;
            }
            
            // Si tiene invitación pendiente, verificar estado sin reenviar
            if (in_array($current_status, [GitHubRegistrationStatus::INVITATION_SENT, GitHubRegistrationStatus::INVITATION_PENDING])) {
                error_log("PLUGIN DEBUG: Verificando estado pendiente para estudiante {$student->id}");
                $github_status = $this->githubAPI->checkInvitationStatus($repo_record->nombre_repo, $student->usuario_github);
                $mapped_status = $this->mapGitHubStatusToDbStatus($github_status);
                
                if ($mapped_status !== $current_status) {
                    $DB->set_field('usuarios_data_patroller', 'invitacion_status', $mapped_status, ['id' => $student->id]);
                    error_log("PLUGIN DEBUG: Estado actualizado para estudiante {$student->id}: {$current_status} -> {$mapped_status}");
                }
                return;
            }
            
            $user_exists = $this->githubAPI->userExists($student->usuario_github);
            
            if (!$user_exists) {
                $DB->set_field('usuarios_data_patroller', 'invitacion_status', 2, ['id' => $student->id]);
                throw new \Exception("Usuario '{$student->usuario_github}' no existe en GitHub");
            }
                        
            // OPTIMIZACIÓN: Enviar invitación (2da llamada API) - el código HTTP ya indica el estado
            $result = $this->githubAPI->inviteStudentToRepo($repo_record->nombre_repo, $student->usuario_github);
            $httpCode = $result['httpCode'];
            
            // Mapear directamente desde código HTTP sin 3ra llamada API
            $new_status = $this->mapHttpCodeToDbStatus($httpCode);
            
            $DB->set_field('usuarios_data_patroller', 'invitacion_status', $new_status, ['id' => $student->id]);
            
            if ($new_status === 2) {
                throw new \Exception("Error HTTP $httpCode al enviar invitación");
            }
            
            error_log("PLUGIN DEBUG: Invitación enviada exitosamente para estudiante {$student->id}, estado: $new_status (HTTP $httpCode)");
            
        } catch (\Exception $e) {
            $DB->set_field('usuarios_data_patroller', 'invitacion_status', 2, ['id' => $student->id]);
            throw $e;
        }
    }
    
    //Cancela una invitación pendiente de GitHub
    public function cancelInvitation(int $user_id): array {
        global $DB;
        
        $result = [
            'success' => false,
            'message' => '',
            'user_data' => null
        ];
        
        try {
            // Obtener datos del usuario (buscar por id_usuario en lugar de id)
            $sql = "SELECT u.id, u.nombre_usuario, u.usuario_github, u.invitacion_status, r.nombre_repo
                    FROM {usuarios_data_patroller} u
                    LEFT JOIN {repositorios_data_patroller} r ON u.id_repo = r.id
                    WHERE u.id_usuario = ? AND u.id_materia = ?";
            
            $user = $DB->get_record_sql($sql, [$user_id, $this->course->id]);
            
            if (!$user) {
                throw new \Exception("Usuario no encontrado");
            }
            
            if (empty($user->usuario_github) || empty($user->nombre_repo)) {
                throw new \Exception("Usuario sin datos de GitHub o repositorio");
            }
            
            // Verificar que tenga una invitación pendiente
            $pending_invitation_id = $this->githubAPI->getPendingInvitationId($user->nombre_repo, $user->usuario_github);
            
            if (empty($pending_invitation_id)) {
                throw new \Exception("No se encontró una invitación pendiente para este usuario");
            }
            
            // Cancelar la invitación en GitHub
            $cancelResult = $this->githubAPI->cancelInvitationById($user->nombre_repo, $pending_invitation_id);
            
            if ($cancelResult) {
                // Actualizar estado en BD a "Cancelado" (estado 5) - usar el ID real del registro
                $DB->update_record('usuarios_data_patroller', [
                    'id' => $user->id,  // Usar el ID del registro encontrado
                    'invitacion_status' => 5
                ]);
                
                $result['success'] = true;
                $result['message'] = "Invitación cancelada exitosamente para {$user->nombre_usuario}";
                $result['user_data'] = $user;
                
            } else {
                throw new \Exception("Error al cancelar la invitación en GitHub");
            }
            
        } catch (\Exception $e) {
            $result['message'] = "Error: " . $e->getMessage();
        }
        
        return $result;
    }

    //Envía una invitación individual a un estudiante específico
    public function sendSingleInvitation(int $user_id): array {
        global $DB;
        
        $result = [
            'success' => false,
            'message' => '',
            'user_data' => null
        ];
        
        try {
            // Obtener datos del usuario
            $sql = "SELECT u.id, u.nombre_usuario, u.usuario_github, u.invitacion_status, r.nombre_repo, r.id as repo_id
                    FROM {usuarios_data_patroller} u
                    LEFT JOIN {repositorios_data_patroller} r ON u.id_repo = r.id
                    WHERE u.id_usuario = ? AND u.id_materia = ?";
            
            $user = $DB->get_record_sql($sql, [$user_id, $this->course->id]);
            
            if (!$user) {
                throw new \Exception("Usuario no encontrado");
            }
            
            if (empty($user->usuario_github)) {
                throw new \Exception("Usuario no tiene configurado su GitHub username");
            }
            
            if (empty($user->nombre_repo)) {
                throw new \Exception("Usuario no tiene repositorio asignado");
            }
            
            if (!$this->githubAPI) {
                throw new \Exception("API de GitHub no configurada");
            }
            
            // Verificar que no sea ya colaborador
            if ($this->githubAPI->isCollaborator($user->nombre_repo, $user->usuario_github)) {
                throw new \Exception("El usuario ya es colaborador del repositorio");
            }
            
            // Enviar invitación usando la API de GitHub
            $invitation_result = $this->githubAPI->inviteStudentToRepo($user->nombre_repo, $user->usuario_github);
            
            // 201: Invitación enviada, 204: Usuario ya es colaborador o invitación pendiente
            if ($invitation_result && isset($invitation_result['httpCode']) && in_array($invitation_result['httpCode'], [201, 204])) {
                // Actualizar estado en BD a "Invitación enviada" (estado 1)
                $DB->update_record('usuarios_data_patroller', [
                    'id' => $user->id,
                    'invitacion_status' => 1
                ]);
                
                $result['success'] = true;
                $result['message'] = "Invitación enviada exitosamente a {$user->nombre_usuario}";
                $result['user_data'] = $user;
                
            } else {
                throw new \Exception("Error al enviar la invitación - HTTP Code: " . ($invitation_result['httpCode'] ?? 'desconocido'));
            }
            
        } catch (\Exception $e) {
            $result['message'] = "Error: " . $e->getMessage();
        }
        
        return $result;
    }
    
    //Mapea estados de GitHub a estados de base de datos
    private function mapGitHubStatusToDbStatus(int $github_status): int {
        return match($github_status) {
            GitHubRegistrationStatus::UNPROCESSED => 0,           // Sin procesar
            GitHubRegistrationStatus::MISSING_USERNAME => 0,      // Sin procesar (no aplica aquí)
            GitHubRegistrationStatus::USERNAME_NOT_FOUND => 2,    // Error
            GitHubRegistrationStatus::INVITATION_SENT => 1,       // Invitación enviada
            GitHubRegistrationStatus::INVITATION_PENDING => 1,    // Invitación enviada (pendiente)
            GitHubRegistrationStatus::ACCEPTED => 3,              // Aceptado
            GitHubRegistrationStatus::ERROR => 2,                 // Error
            default => 2 // Error por defecto
        };
    }
    
    //Mapea códigos HTTP a estados de base de datos
    private function mapHttpCodeToDbStatus(int $http_code): int {
        return match($http_code) {
            201 => 1,    // Created - Invitación enviada
            204 => 3,    // No Content - Ya es colaborador
            404 => 2,    // Not Found - Usuario/repo no existe
            422 => 2,    // Unprocessable - Validación fallida
            default => 2 // Error por defecto
        };
    }

    public function syncInvitationStatuses(): array {
        global $DB;
        
        if (!$this->githubAPI) {
            throw new \Exception('GitHub API no está configurada');
        }
        
        // Obtener todos los estudiantes con GitHub username
        $sql = "SELECT u.*, r.nombre_repo 
                FROM {usuarios_data_patroller} u
                INNER JOIN {repositorios_data_patroller} r ON u.id_repo = r.id
                WHERE r.id_materia = :course_id 
                AND u.usuario_github IS NOT NULL 
                AND u.usuario_github != ''";
        
        $students = $DB->get_records_sql($sql, ['course_id' => $this->course->id]);
        
        $updated = 0;
        $errors = 0;
        $results = [];
        
        foreach ($students as $student) {
            try {
                $result = $this->fetchAndStoreStatusForStudent($student);
                $updated += $result['updated'] ? 1 : 0;
                $results[] = $result;
            } catch (\Exception $e) {
                error_log("Error sincronizando estudiante {$student->usuario_github}: " . $e->getMessage());
                $errors++;
                $results[] = [
                    'student_id' => $student->id,
                    'github_username' => $student->usuario_github,
                    'updated' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'updated' => $updated,
            'errors' => $errors,
            'total' => count($students),
            'details' => $results
        ];
    }
    
    /**
     * Obtiene y almacena el estado actual de un estudiante desde GitHub
     */
    private function fetchAndStoreStatusForStudent(object $student): array {
        global $DB;
        
        try {
            // 1. Verificar si el usuario GitHub existe
            $user_exists = $this->githubAPI->userExists($student->usuario_github);
            if (!$user_exists) {
                $new_status = 2; // Error - usuario no existe
            } else {
                // 2. Verificar si es colaborador activo
                $is_collaborator = $this->githubAPI->isCollaborator($student->nombre_repo, $student->usuario_github);
                
                if ($is_collaborator) {
                    $new_status = 3; // Aceptado/Colaborador
                } else {
                    // 3. Verificar invitaciones pendientes
                    $invitation_details = $this->githubAPI->getPendingInvitationDetails($student->nombre_repo, $student->usuario_github);
                    $has_pending_invitation = !empty($invitation_details);
                    
                    if ($has_pending_invitation) {
                        $new_status = 1; // Invitación pendiente
                    } else {
                        $new_status = 0; // Sin invitación
                    }
                }
            }
            
            // Actualizar BD solo si el estado cambió
            $old_status = intval($student->invitacion_status ?? 0);
            $updated = false;
            
            if ($old_status !== $new_status || empty($student->invitacion_status_updated)) {
                try {
                    $DB->update_record('usuarios_data_patroller', [
                        'id' => $student->id,
                        'invitacion_status' => $new_status,
                        'invitacion_status_updated' => time()
                    ]);
                    $updated = true;
                } catch (\Exception $db_e) {
                    // Si falla porque el campo no existe, solo actualizar invitacion_status
                    $DB->update_record('usuarios_data_patroller', [
                        'id' => $student->id,
                        'invitacion_status' => $new_status
                    ]);
                    $updated = true;
                }
            } else {
                // Solo actualizar timestamp si el campo existe
                try {
                    $DB->update_record('usuarios_data_patroller', [
                        'id' => $student->id,
                        'invitacion_status_updated' => time()
                    ]);
                } catch (\Exception $db_e) {
                    // Campo no existe, ignorar actualización de timestamp
                }
            }
            
            return [
                'student_id' => $student->id,
                'github_username' => $student->usuario_github,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'updated' => $updated
            ];
            
        } catch (\Exception $e) {
            // En caso de error, marcar como error y actualizar timestamp
            try {
                $DB->update_record('usuarios_data_patroller', [
                    'id' => $student->id,
                    'invitacion_status' => 2, // Error
                    'invitacion_status_updated' => time()
                ]);
            } catch (\Exception $db_e) {
                // Si falla porque el campo no existe, solo actualizar invitacion_status
                $DB->update_record('usuarios_data_patroller', [
                    'id' => $student->id,
                    'invitacion_status' => 2 // Error
                ]);
            }
            
            throw $e;
        }
    }

    //Resetea los estados de invitación para todos los estudiantes de un curso
    public function resetInvitationStatuses(int $course_id): void {
        global $DB;

        try {
            $count = $DB->execute(
                "UPDATE {usuarios_data_patroller} SET invitacion_status = 0 WHERE id_materia = ?", 
                [$course_id]
            );
        
            error_log("PLUGIN INFO: Estados de invitación reseteados para curso {$course_id}");
        
        } catch (\Exception $e) {
            error_log("PLUGIN ERROR: Error al resetear estados de invitación: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Inicializa estados faltantes - versión simplificada
     * No requiere campo invitacion_status_updated
     */
    public function initializeMissingStatuses(): array {
        global $DB;
        
        // Obtener estudiantes con GitHub username (versión simple)
        $sql = "SELECT u.* 
                FROM {usuarios_data_patroller} u
                INNER JOIN {repositorios_data_patroller} r ON u.id_repo = r.id
                WHERE r.id_materia = :course_id 
                AND u.usuario_github IS NOT NULL 
                AND u.usuario_github != ''";
        
        $students = $DB->get_records_sql($sql, ['course_id' => $this->course->id]);
        
        $initialized = 0;
        $errors = 0;
        
        foreach ($students as $student) {
            try {
                // Marcar como "sin procesar" con timestamp actual
                try {
                    $DB->update_record('usuarios_data_patroller', [
                        'id' => $student->id,
                        'invitacion_status' => 0,
                        'invitacion_status_updated' => time()
                    ]);
                } catch (\Exception $db_e) {
                    // Si falla porque el campo no existe, solo actualizar invitacion_status
                    $DB->update_record('usuarios_data_patroller', [
                        'id' => $student->id,
                        'invitacion_status' => 0
                    ]);
                }
                $initialized++;
            } catch (\Exception $e) {
                error_log("Error inicializando estado para estudiante {$student->id}: " . $e->getMessage());
                $errors++;
            }
        }
        
        return [
            'initialized' => $initialized,
            'errors' => $errors,
            'total' => count($students)
        ];
    }
}
