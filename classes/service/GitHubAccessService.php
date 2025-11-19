<?php
namespace mod_pluginpatroller\service;

defined('MOODLE_INTERNAL') || die();

// Asegurar acceso a funciones globales de Moodle
global $CFG;
require_once($CFG->dirroot.'/lib/moodlelib.php');

use mod_pluginpatroller\model\UserModel;
use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;
use mod_pluginpatroller\helpers\UserHelper;

/**
 * Servicio especializado para gesti├│n de accesos GitHub
 * Maneja invitaciones, cambios de usuarios y repositorios
 */
class GitHubAccessService {
    
    private $course;
    private $githubAPI;
    
    public function __construct($course) {
        $this->course = $course;
        // Inicializar API de GitHub
        $this->githubAPI = $this->instanceAPI();
    }

    /**
     * Instancia la API de GitHub
     */
    private function instanceAPI(): ?GitHubPatrollerAPI {
        $token = ConfigHelper::getGitHubToken();
        $owner = ConfigHelper::getGitHubOwner();
        if ($token && $owner) {
            return new GitHubPatrollerAPI($owner, $token,);
        }
        return null;
    }
    
    /**
     * Procesa invitaciones masivas por repositorio
     */
    public function processInvitations(string $repository_selected, bool $force_resend = false): string {
        // Verificaciones detalladas de configuraci├│n
        if (!$this->githubAPI) {
            // API not configured
            throw new \Exception('Γ¥î API de GitHub no configurada - Verifica token y owner en configuraci├│n');
        }

        // Probar conectividad b├ísica verificando si podemos hacer una consulta simple
        try {
            // Usar un m├⌐todo que sabemos que existe para probar la conexi├│n
            $test_user = $this->githubAPI->userExists('github'); // GitHub siempre existe
            
        } catch (\Exception $conn_error) {
            throw new \Exception('Γ¥î Error de conectividad GitHub: ' . $conn_error->getMessage());
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
    
    /**
     * Procesa cambios guardados desde el formulario (solo repositorios)
     */
    public function processSaveChanges(array $github_changes, array $repo_changes): void {
        // Los cambios de GitHub username no se procesan desde la vista de profesores
        // Solo los estudiantes pueden cambiar su username desde su vista
        
        if (!empty($repo_changes)) {
            $this->processRepositoryChanges($repo_changes);
        }
    }
    
    /**
     * Obtiene estudiantes con todos sus datos de GitHub para la tabla
     */
    public function getStudentsWithGitHubData($cm_id = null): array {
        global $DB;
        
        // Consulta SQL robusta que funciona aunque el campo invitacion_status_updated no exista a├║n
        try {
            $sql = "SELECT u.*, 
                           COALESCE(u.invitacion_status, 0) as invitacion_status,
                           COALESCE(u.invitacion_status_updated, 0) as invitacion_status_updated
                    FROM {usuarios_data_patroller} u 
                    WHERE u.id_materia = :course_id";
            
            $students = $DB->get_records_sql($sql, ['course_id' => $this->course->id]);
        } catch (\Exception $e) {
            // Si falla porque el campo no existe, usar consulta simple
            $students = $DB->get_records('usuarios_data_patroller', ['id_materia' => $this->course->id]);
            
            // Agregar campos faltantes como propiedades del objeto
            foreach ($students as $student) {
                $student->invitacion_status = intval($student->invitacion_status ?? 0);
                $student->invitacion_status_updated = 0; // Campo no existe a├║n
            }
        }
        $repositories = $DB->get_records('repositorios_data_patroller', ['id_materia' => $this->course->id]);
        
        $formatted_students = [];
        
        foreach ($students as $student) {
            if (!$student->id_repo) {
                continue;
            }
            
            $repo = $repositories[$student->id_repo] ?? null;
            if (!$repo) {
                continue;
            }
            
            $user_data = $DB->get_record('user', ['id' => $student->id_usuario]);
            $enriched_user = UserHelper::enrichWithSedeAndCurso($this->course, $user_data);
            
            $formatted_student = $this->formatStudentForTable($student, $repo, $enriched_user);
            
            // Agregar cm_id si se proporciona
            if ($cm_id) {
                $formatted_student['cm_id'] = $cm_id;
            }
            
            $formatted_students[] = $formatted_student;
        }
                
        return $formatted_students;
    }
    
    /**
     * Verifica si hay estudiantes que pueden ser actualizados
     */
    public function hasUpdatableStudents(array $students_data): bool {
        foreach ($students_data as $student) {
            if (!$student['is_readonly']) {
                return true;
            }
        }
        return false;
    }
    
    // ========== CORRECCI├ôN P0: OPTIMIZACI├ôN DE INVITACI├ôN ==========
    
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
                $results[] = "Γ¥î Repositorio '$repo_name': No encontrado en BD";
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
                    $results[] = "ΓÜá∩╕Å Estudiante {$student->id}: Sin usuario GitHub configurado";
                    continue;
                }
                
                // Solo bloquear estado aceptado (colaboradores activos)
                if ($student->invitacion_status == 3) {
                    $results[] = "Γ£à Estudiante '{$student->usuario_github}': Ya es colaborador activo";
                    continue;
                }
                
                $total_processed++;
                try {
                    $this->sendGitHubInvitation($student, $repo_record);
                    $results[] = "Invitaci├│n procesada para '{$student->usuario_github}' en '{$repo_name}'";
                } catch (\Exception $e) {
                    $total_errors++;
                    $results[] = "Γ¥î Error con '{$student->usuario_github}' en '{$repo_name}': " . $e->getMessage();
                }
            }
        }
        
        $summary = "Resumen: $total_processed estudiantes procesados";
        if ($total_errors > 0) {
            $summary .= ", $total_errors errores";
        }
        
        return $summary . " | " . implode(" | ", $results);
    }
    
    private function sendGitHubInvitation($student, $repo_record): void {
        global $DB;
                
        try {
            if (!$this->githubAPI) {
                throw new \Exception('API de GitHub no configurada - verifica token y owner');
            }
            
            $current_status = (int)($student->invitacion_status ?? GitHubRegistrationStatus::UNPROCESSED);
            
            // Si ya est├í aceptado, no hacer nada
            if ($current_status === GitHubRegistrationStatus::ACCEPTED) {
                error_log("PLUGIN DEBUG: Estudiante {$student->id} ya es colaborador en {$repo_record->nombre_repo}");
                return;
            }
            
            // Si tiene invitaci├│n pendiente, verificar estado sin reenviar
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
                        
            // OPTIMIZACI├ôN: Enviar invitaci├│n (2da llamada API) - el c├│digo HTTP ya indica el estado
            $result = $this->githubAPI->inviteStudentToRepo($repo_record->nombre_repo, $student->usuario_github);
            $httpCode = $result['httpCode'];
            
            // Mapear directamente desde c├│digo HTTP sin 3ra llamada API
            $new_status = $this->mapHttpCodeToDbStatus($httpCode);
            
            $DB->set_field('usuarios_data_patroller', 'invitacion_status', $new_status, ['id' => $student->id]);
            
            if ($new_status === 2) {
                throw new \Exception("Error HTTP $httpCode al enviar invitaci├│n");
            }
            
            error_log("PLUGIN DEBUG: Invitaci├│n enviada exitosamente para estudiante {$student->id}, estado: $new_status (HTTP $httpCode)");
            
        } catch (\Exception $e) {
            $DB->set_field('usuarios_data_patroller', 'invitacion_status', 2, ['id' => $student->id]);
            throw $e;
        }
    }
    
    /**
     * Usa constantes de GitHubRegistrationStatus pero mapea a estados de BD (0-3)
     */
    private function mapGitHubStatusToDbStatus(int $github_status): int {
        return match($github_status) {
            GitHubRegistrationStatus::UNPROCESSED => 0,           // Sin procesar
            GitHubRegistrationStatus::MISSING_USERNAME => 0,      // Sin procesar (no aplica aqu├¡)
            GitHubRegistrationStatus::USERNAME_NOT_FOUND => 2,    // Error
            GitHubRegistrationStatus::INVITATION_SENT => 1,       // Invitaci├│n enviada
            GitHubRegistrationStatus::INVITATION_PENDING => 1,    // Invitaci├│n enviada (pendiente)
            GitHubRegistrationStatus::ACCEPTED => 3,              // Aceptado
            GitHubRegistrationStatus::ERROR => 2,                 // Error
            default => 2 // Error por defecto
        };
    }
    
    /**
     * Evita la 3ra llamada API innecesaria
     */
    private function mapHttpCodeToDbStatus(int $httpCode): int {
        return match($httpCode) {
            201, 202 => 1, // Invitaci├│n creada/enviada
            204 => 3,      // Ya es colaborador
            404 => 2,      // Repo/usuario no encontrado
            403 => 2,      // Sin permisos
            422 => 2,      // Usuario inv├ílido
            default => 2   // Error gen├⌐rico
        };
    }
    
    // ========== M├ëTODOS SIN CAMBIOS ==========

    private function processRepositoryChanges(array $repo_changes): void {
        global $DB;

        foreach ($repo_changes as $student_id => $new_repo_id) {
            // Si no hay repo asignado, saltar
            if (empty($new_repo_id)) {
                continue;
            }
            // Asegurar que el valor sea un entero
            $repo_id_clean = (int)$new_repo_id;
            // Buscar registro del estudiante
            $student = $DB->get_record('usuarios_data_patroller', ['id' => $student_id]);
            if (!$student) {
                continue; // no existe en BD
            }
            // Verificar si el registro est├í en estado de solo lectura
            if (in_array($student->invitacion_status, [3, 4, 5])) {
                continue; // aceptado o pendiente ΓåÆ no modificar
            }
            // Preparar objeto para actualizaci├│n
            $update = (object)[
                'id' => $student_id,
                'id_repo' => $repo_id_clean,
                'invitacion_status' => 0 // reset a "no enviada" siempre que cambia de repo
            ];
            // Guardar cambios
            $DB->update_record('usuarios_data_patroller', $update);
        }
    }
    
    private function formatStudentForTable(object $student, object $repo, object $user): array {
        $is_readonly = in_array($student->invitacion_status, [3, 4, 5]);
        
        // Obtener estado GitHub en tiempo real
        $github_status_data = $this->getGitHubStatusForStudent($student, $repo);
        
        return [
            'user_id' => $student->id_usuario, // Revertir temporalmente para debug
            'full_name' => htmlspecialchars($user->firstname . ' ' . $user->lastname),
            'email' => htmlspecialchars($user->email),
            'initial' => strtoupper(substr($user->firstname, 0, 1)),
            'sede' => htmlspecialchars($user->sede ?? ''),
            'curso' => htmlspecialchars($user->curso ?? ''),
            'github_username' => $student->usuario_github,
            'readonly_github' => true, // Siempre readonly para profesores
            'repository_name' => htmlspecialchars($repo->nombre_repo),
            'repository_options' => $this->getRepositoryOptionsForStudent($student, $repo),
            'can_invite' => !empty($student->usuario_github) && !empty($student->id_repo),
            'is_readonly' => $is_readonly,
            
            // Solo datos GitHub en tiempo real
            'github_status_data' => $github_status_data,
            'is_collaborator' => $github_status_data['is_collaborator'] ?? false,
            'has_pending_invitation' => $this->determineHasPendingInvitation($github_status_data, $student)
        ];
    }

    /**
     * Obtiene el estado GitHub real de un estudiante espec├¡fico
     */
    private function getGitHubStatusForStudent(object $student, object $repo): ?array {
        // Si no tiene GitHub username, no verificar
        if (empty($student->usuario_github)) {
            return null;
        }
        
        // USAR DATOS REALES: Solo usar datos de BD existentes, sin simulaci├│n
        $status = intval($student->invitacion_status ?? 0);
        $last_updated = intval($student->invitacion_status_updated ?? 0);
        
        // Para compatibilidad: si no hay timestamp pero hay estado, asumir desactualizado
        $is_outdated = $last_updated === 0 || (time() - $last_updated) > 3600;
        
        switch ($status) {
            case 3: // Aceptado/Colaborador
                return [
                    'text' => \get_string('collaboratoractive', 'mod_pluginpatroller'),
                    'class' => 'success', // Verde
                    'icon' => 'fas fa-check-circle',
                    'is_collaborator' => true,
                    'has_pending_invitation' => false
                ];
                
            case 1: // Invitaci├│n enviada/pendiente
                // Verificar si est├í expirada (m├ís de 7 d├¡as)
                $is_expired = $last_updated > 0 && (time() - $last_updated) > (7 * 24 * 3600);
                
                if ($is_expired) {
                    return [
                        'text' => \get_string('invitationexpired', 'mod_pluginpatroller'),
                        'class' => 'dark', // Gris oscuro/Negro
                        'icon' => 'fas fa-hourglass-end',
                        'is_collaborator' => false,
                        'has_pending_invitation' => true
                    ];
                } else {
                    return [
                        'text' => \get_string('invitationpending', 'mod_pluginpatroller'),
                        'class' => 'warning', // Amarillo
                        'icon' => 'fas fa-clock',
                        'is_collaborator' => false,
                        'has_pending_invitation' => true
                    ];
                }
                
            case 2: // Error al enviar - tratarlo como sin procesar para permitir reintento
                return [
                    'text' => \get_string('noinvitation', 'mod_pluginpatroller'),
                    'class' => 'danger', // Rojo
                    'icon' => 'fas fa-times',
                    'is_collaborator' => false,
                    'has_pending_invitation' => false
                ];
                
            case 0: // Sin procesar
            default:
                return [
                    'text' => \get_string('noinvitation', 'mod_pluginpatroller'), 
                    'class' => 'danger', // Rojo
                    'icon' => 'fas fa-times',
                    'is_collaborator' => false,
                    'has_pending_invitation' => false
                ];
        }
    }

    /**
     * Verifica si una invitaci├│n est├í expirada (m├ís de 7 d├¡as)
     */
    private function isInvitationExpired(?string $created_at): bool {
        if (empty($created_at)) {
            return false;
        }
        
        try {
            $invitation_date = new \DateTime($created_at);
            $now = new \DateTime();
            $diff = $now->diff($invitation_date);
            
            // Considerar expirada si tiene m├ís de 7 d├¡as
            return $diff->days > 7;
            
        } catch (\Exception $e) {
            error_log("Error calculando expiraci├│n de invitaci├│n: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Determina si un usuario tiene una invitaci├│n pendiente
     * Usa GitHub en tiempo real si est├í disponible, fallback a BD si hay error
     */
    private function determineHasPendingInvitation(?array $github_status_data, object $student): bool {
        // Si tenemos datos de GitHub v├ílidos, usarlos
        if ($github_status_data !== null) {
            return $github_status_data['has_pending_invitation'] ?? false;
        }
        
        // Fallback: usar estado de BD si no podemos verificar con GitHub
        // Estado 1 = Invitaci├│n enviada, podr├¡a necesitar cancelaci├│n
        // No mostrar bot├│n cancelar si ya est├í cancelado (5) o rechazado (4)
        return in_array($student->invitacion_status, [1]);
    }

    /**
     * Actualiza el estado en BD bas├índose en el estado real de GitHub
     */
    private function updateDatabaseStatusIfNeeded(object $student, bool $is_collaborator, bool $has_pending_invitation): void {
        global $DB;
        
        $current_status = $student->invitacion_status;
        $new_status = null;
        
        // Solo actualizar en casos espec├¡ficos para evitar loops infinitos
        if ($is_collaborator && $current_status == 1) {
            // BD dice "enviada" pero GitHub dice "aceptada" -> actualizar a aceptado
            $new_status = 3;
            
        } elseif (!$is_collaborator && !$has_pending_invitation && $current_status == 1) {
            // BD dice "enviada" pero GitHub no tiene invitaci├│n pendiente ni es colaborador
            // Esto significa que la invitaci├│n fue rechazada o cancelada -> marcar como rechazada
            $new_status = 4; // Rechazado
        }
        
        // Actualizar BD si es necesario
        if ($new_status !== null) {
            $DB->set_field('usuarios_data_patroller', 'invitacion_status', $new_status, ['id' => $student->id]);
            // Actualizar el objeto en memoria para evitar inconsistencias
            $student->invitacion_status = $new_status;
        }
    }

    
    /*
     * M├ëTODOS LEGACY - YA NO SE USAN (solo estados GitHub en tiempo real)
     * Se mantienen comentados por si se necesitan restaurar estados BD
     */
    
    /*
    private function getStatusClass(int $status): string {
        return match($status) {
            0 => 'secondary',  // Sin procesar
            1 => 'primary',    // Enviado
            2 => 'danger',     // Error
            3 => 'success',    // Aceptado
            4 => 'danger',     // Rechazado
            5 => 'warning',    // Cancelado
            default => 'secondary'
        };
    }
    
    private function getStatusIcon(int $status): string {
        return match($status) {
            0 => 'fas fa-clock',        // Sin procesar
            1 => 'fas fa-paper-plane',  // Enviado
            2 => 'fas fa-exclamation-triangle', // Error
            3 => 'fas fa-check-circle', // Aceptado
            4 => 'fas fa-times-circle', // Rechazado
            5 => 'fas fa-ban',          // Cancelado
            default => 'fas fa-question'
        };
    }
    
    private function getStatusText(int $status): string {
        // Return internationalized strings using Moodle get_string()
        switch ($status) {
            case 0:
                return get_string('status_unprocessed', 'mod_pluginpatroller');
            case 1:
                return get_string('status_sent', 'mod_pluginpatroller');
            case 2:
                return get_string('status_error_sending', 'mod_pluginpatroller');
            case 3:
                return get_string('status_accepted', 'mod_pluginpatroller');
            case 4:
                return get_string('status_pending', 'mod_pluginpatroller');
            case 5:
                return get_string('status_sent_legacy', 'mod_pluginpatroller');
            case 6:
                return get_string('status_error', 'mod_pluginpatroller');
            default:
                return get_string('status_unknown', 'mod_pluginpatroller');
        }
    }
    */
    
    private function getRepositoryOptionsForStudent(object $student, object $current_repo): array {
        $repositories = RepositoryModel::getAllByCourseId($this->course->id);
        $options = [];
        
        foreach ($repositories as $repo_id => $repo_name) {
            $options[] = [
                'value' => $repo_id,
                'text' => htmlspecialchars($repo_name),
                'selected' => ($repo_id == $current_repo->id)
            ];
        }
        
        return $options;
    }
    
    /**
     * Test de conectividad con la API de GitHub
     */
    public function testConnection(): string {
        if (!$this->githubAPI) {
            return "Γ¥î API de GitHub no configurada";
        }
        
        try {
            // Test simple: verificar si un usuario conocido existe
            $test_user_exists = $this->githubAPI->userExists('github');
            return $test_user_exists ? "Γ£à Conectividad OK" : "Γ¥î Error de conectividad";
        } catch (\Exception $e) {
            return "Γ¥î Error: " . $e->getMessage();
        }
    }

    /**
     * Obtiene tabla de comparaci├│n entre estado de BD y GitHub
     */
    public function getInvitationStatusTable(): array {
        global $DB;
        
        $table_data = [];
        
        // Obtener todos los estudiantes con invitaciones enviadas (estado 1) o aceptadas (estado 3)
        $sql = "SELECT u.id, u.nombre_usuario, u.usuario_github, u.invitacion_status, r.nombre_repo, r.id as repo_id
                FROM {usuarios_data_patroller} u
                LEFT JOIN {repositorios_data_patroller} r ON u.id_repo = r.id
                WHERE u.id_materia = ? AND u.invitacion_status IN (1, 3)
                ORDER BY r.nombre_repo, u.nombre_usuario";
        
        $users = $DB->get_records_sql($sql, [$this->course->id]);
        
        foreach ($users as $user) {
            if (empty($user->usuario_github) || empty($user->nombre_repo)) {
                continue;
            }
            
            $github_status = 'Error';
            $is_collaborator = false;
            $has_pending_invitation = false;
            
            try {
                // Verificar si es colaborador activo
                $is_collaborator = $this->githubAPI->isCollaborator($user->nombre_repo, $user->usuario_github);
                
                // Verificar invitaciones pendientes solo si no es colaborador
                if (!$is_collaborator) {
                    $pending_invitation_id = $this->githubAPI->getPendingInvitationId($user->nombre_repo, $user->usuario_github);
                    $has_pending_invitation = !empty($pending_invitation_id);
                }
                
                // Determinar estado de GitHub
                if ($is_collaborator) {
                    $github_status = \get_string('collaboratoractive', 'mod_pluginpatroller');
                } elseif ($has_pending_invitation) {
                    $github_status = \get_string('invitationpending', 'mod_pluginpatroller');
                } else {
                    $github_status = \get_string('noinvitation', 'mod_pluginpatroller');
                }
                
            } catch (\Exception $e) {
                $github_status = 'Error: ' . $e->getMessage();
            }
            
            // Mapear estado de BD a texto
            $bd_status = match($user->invitacion_status) {
                0 => 'No Enviada',
                1 => 'Invitaci├│n Enviada',
                2 => 'Error',
                3 => 'Aceptado',
                4 => 'Rechazado',
                5 => 'Cancelado',
                default => 'Desconocido'
            };
            
            // Determinar si hay discrepancia
            $discrepancy = false;
            $collaborator_active_text = \get_string('collaboratoractive', 'mod_pluginpatroller');
            if ($user->invitacion_status == 1 && $github_status == $collaborator_active_text) {
                $discrepancy = true; // BD dice "enviada" pero GitHub dice "aceptada"
            } elseif ($user->invitacion_status == 3 && $github_status != $collaborator_active_text) {
                $discrepancy = true; // BD dice "aceptada" pero GitHub no lo confirma
            }
            
            $table_data[] = [
                'user_id' => $user->id,
                'nombre_usuario' => $user->nombre_usuario,
                'usuario_github' => $user->usuario_github,
                'repositorio' => $user->nombre_repo,
                'estado_bd' => $bd_status,
                'estado_github' => $github_status,
                'discrepancia' => $discrepancy,
                'is_collaborator' => $is_collaborator,
                'has_pending_invitation' => $has_pending_invitation
            ];
        }
        
        return $table_data;
    }

    /**
     * Cancela una invitaci├│n pendiente espec├¡fica
     */
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
            
            // Verificar que tenga una invitaci├│n pendiente
            $pending_invitation_id = $this->githubAPI->getPendingInvitationId($user->nombre_repo, $user->usuario_github);
            
            if (empty($pending_invitation_id)) {
                throw new \Exception("No se encontr├│ una invitaci├│n pendiente para este usuario");
            }
            
            // Cancelar la invitaci├│n en GitHub
            $cancelResult = $this->githubAPI->cancelInvitationById($user->nombre_repo, $pending_invitation_id);
            
            if ($cancelResult) {
                // Actualizar estado en BD a "Cancelado" (estado 5) - usar el ID real del registro
                $DB->update_record('usuarios_data_patroller', [
                    'id' => $user->id,  // Usar el ID del registro encontrado
                    'invitacion_status' => 5
                ]);
                
                $result['success'] = true;
                $result['message'] = "Invitaci├│n cancelada exitosamente para {$user->nombre_usuario}";
                $result['user_data'] = $user;
                
            } else {
                throw new \Exception("Error al cancelar la invitaci├│n en GitHub");
            }
            
        } catch (\Exception $e) {
            $result['message'] = "Error: " . $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Env├¡a una invitaci├│n individual a un estudiante espec├¡fico
     */
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
            
            // Enviar invitaci├│n usando la API de GitHub
            $invitation_result = $this->githubAPI->inviteStudentToRepo($user->nombre_repo, $user->usuario_github);
            
            // 201: Invitaci├│n enviada, 204: Usuario ya es colaborador o invitaci├│n pendiente
            if ($invitation_result && isset($invitation_result['httpCode']) && in_array($invitation_result['httpCode'], [201, 204])) {
                // Actualizar estado en BD a "Invitaci├│n enviada" (estado 1)
                $DB->update_record('usuarios_data_patroller', [
                    'id' => $user->id,
                    'invitacion_status' => 1
                ]);
                
                $result['success'] = true;
                $result['message'] = "Invitaci├│n enviada exitosamente a {$user->nombre_usuario} para el repositorio {$user->nombre_repo}";
                $result['user_data'] = $user;
            } else {
                throw new \Exception("Error al enviar la invitaci├│n a trav├⌐s de GitHub API");
            }
            
        } catch (\Exception $e) {
            $result['message'] = "Error: " . $e->getMessage();
        }
        
        return $result;
    }

    // ========== M├ëTODOS DE SINCRONIZACI├ôN ==========
    
    /**
     * Inicializa estados faltantes - versi├│n simplificada
     * No requiere campo invitacion_status_updated
     */
    public function initializeMissingStatuses(int $course_id): array {
        global $DB;
        
        // Obtener estudiantes con GitHub username (versi├│n simple)
        $sql = "SELECT u.* 
                FROM {usuarios_data_patroller} u
                INNER JOIN {repositorios_data_patroller} r ON u.id_repo = r.id
                WHERE r.id_materia = :course_id 
                AND u.usuario_github IS NOT NULL 
                AND u.usuario_github != ''";
        
        $students = $DB->get_records_sql($sql, ['course_id' => $course_id]);
        
        $students = $DB->get_records_sql($sql, ['course_id' => $course_id]);
        
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
    
    /**
     * Sincroniza estados de invitaci├│n con GitHub API
     * Versi├│n simplificada que funciona sin campo invitacion_status_updated
     */
    public function syncInvitationStatuses(int $course_id): array {
        global $DB;
        
        if (!$this->githubAPI) {
            throw new \Exception('GitHub API no est├í configurada');
        }
        
        // Obtener todos los estudiantes con GitHub username (versi├│n simple)
        $sql = "SELECT u.*, r.nombre_repo 
                FROM {usuarios_data_patroller} u
                INNER JOIN {repositorios_data_patroller} r ON u.id_repo = r.id
                WHERE r.id_materia = :course_id 
                AND u.usuario_github IS NOT NULL 
                AND u.usuario_github != ''";
        
        $students = $DB->get_records_sql($sql, ['course_id' => $course_id]);
        
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
                        $new_status = 1; // Invitaci├│n pendiente
                    } else {
                        $new_status = 0; // Sin invitaci├│n
                    }
                }
            }
            
            // Actualizar BD solo si el estado cambi├│
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
                    // Campo no existe, ignorar actualizaci├│n de timestamp
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

}
