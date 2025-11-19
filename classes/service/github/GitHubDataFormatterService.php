<?php
namespace mod_pluginpatroller\service\github;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\helpers\UserHelper;

/**
 * Servicio para formateo de datos de GitHub:
 * - Formatear datos de estudiantes para tablas
 * - Generar opciones de repositorios para dropdowns
 * - Crear tablas comparativas de estados (BD vs GitHub)
 */
class GitHubDataFormatterService {
    
    private $course;
    private $githubAPI;
    private $statusService;

    public function __construct($course) {
        $this->course = $course;
        $api_service = new GitHubApiService();
        $this->githubAPI = $api_service->getAPI();
        $this->statusService = new GitHubStatusService();
    }
    
    //Obtiene estudiantes con todos sus datos de GitHub para la tabla
    public function getStudentsWithGitHubData($cm_id = null): array {
        global $DB;
        
        $students = $DB->get_records('usuarios_data_patroller', ['id_materia' => $this->course->id]);
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
    
    // Verifica si hay estudiantes que pueden ser actualizados
    public function hasUpdatableStudents(array $students_data): bool {
        foreach ($students_data as $student) {
            if (!$student['is_readonly']) {
                return true;
            }
        }
        return false;
    }
    
    // Formatea los datos de un estudiante para mostrar en tabla
    public function formatStudentForTable(object $student, object $repo, object $user): array {
        $is_readonly = in_array($student->invitacion_status, [3, 4, 5]);
        
        // Obtener estado GitHub en tiempo real
        $github_status_data = $this->statusService->getGitHubStatusForStudent($student, $repo);
        
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
            'has_pending_invitation' => $this->statusService->determineHasPendingInvitation($github_status_data, $student)
        ];
    }
    
    // Obtiene opciones de repositorio para un estudiante
    public function getRepositoryOptionsForStudent(object $student, object $current_repo): array {
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
    
    // Genera tabla comparativa de estados entre BD y GitHub
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
                    $github_status = 'Colaborador Activo';
                } elseif ($has_pending_invitation) {
                    $github_status = 'Invitación Pendiente';
                } else {
                    $github_status = 'Sin Invitación';
                }
                
            } catch (\Exception $e) {
                $github_status = 'Error: ' . $e->getMessage();
            }
            
            // Mapear estado de BD a texto
            $bd_status = match($user->invitacion_status) {
                0 => 'No Enviada',
                1 => 'Invitación Enviada',
                2 => 'Error',
                3 => 'Aceptado',
                4 => 'Rechazado',
                5 => 'Cancelado',
                default => 'Desconocido'
            };
            
            // Determinar si hay discrepancia
            $discrepancy = false;
            if ($user->invitacion_status == 1 && $github_status == 'Colaborador Activo') {
                $discrepancy = true; // BD dice "enviada" pero GitHub dice "aceptada"
            } elseif ($user->invitacion_status == 3 && $github_status != 'Colaborador Activo') {
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
}
