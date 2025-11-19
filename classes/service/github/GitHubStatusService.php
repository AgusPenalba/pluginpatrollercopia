<?php
namespace mod_pluginpatroller\service\github;

defined('MOODLE_INTERNAL') || die();

/**
 * Servicio para gestión de estados de GitHub:
 * - Verificar estados de invitaciones y colaboradores en GitHub
 * - Sincronizar estados entre GitHub y la base de datos
 * - Determinar expiración de invitaciones
 * - Proporcionar información de estado para la UI
 */
class GitHubStatusService {
    
    private $githubAPI;
    
    public function __construct() {
        $api_service = new GitHubApiService();
        $this->githubAPI = $api_service->getAPI();
    }
    
    //Obtiene el estado de GitHub para un estudiante en un repositorio
    public function getGitHubStatusForStudent(object $student, object $repo): ?array {
        // Si no tiene GitHub username o repo, no verificar
        if (empty($student->usuario_github) || empty($repo->nombre_repo) || !$this->githubAPI) {
            return null;
        }
        
        try {
            // 1. Verificar si el usuario GitHub existe
            $user_exists = $this->githubAPI->userExists($student->usuario_github);
            if (!$user_exists) {
                return [
                    'text' => 'Usuario No Existe',
                    'class' => 'danger',
                    'icon' => 'fas fa-user-times',
                    'is_collaborator' => false,
                    'has_pending_invitation' => false
                ];
            }

            // 2. Verificar si es colaborador activo
            $is_collaborator = $this->githubAPI->isCollaborator($repo->nombre_repo, $student->usuario_github);
            
            // 3. Verificar invitaciones pendientes solo si no es colaborador
            $has_pending_invitation = false;
            $invitation_details = null;
            if (!$is_collaborator) {
                $invitation_details = $this->githubAPI->getPendingInvitationDetails($repo->nombre_repo, $student->usuario_github);
                $has_pending_invitation = !empty($invitation_details);
            }
            
            // 4. Sincronizar estado de BD con GitHub automáticamente
            $this->updateDatabaseStatusIfNeeded($student, $is_collaborator, $has_pending_invitation);
            
            // 5. Determinar estado y estilos
            if ($is_collaborator) {
                return [
                    'text' => 'Colaborador Activo',
                    'class' => 'success',
                    'icon' => 'fas fa-check-circle',
                    'is_collaborator' => true,
                    'has_pending_invitation' => false
                ];
            } elseif ($has_pending_invitation) {
                // Verificar si la invitación está expirada (más de 7 días)
                $is_expired = $this->isInvitationExpired($invitation_details['created_at'] ?? null);
                
                if ($is_expired) {
                    return [
                        'text' => 'Invitación Expirada',
                        'class' => 'dark',
                        'icon' => 'fas fa-hourglass-end',
                        'is_collaborator' => false,
                        'has_pending_invitation' => true
                    ];
                } else {
                    return [
                        'text' => 'Invitación Pendiente',
                        'class' => 'warning',
                        'icon' => 'fas fa-clock',
                        'is_collaborator' => false,
                        'has_pending_invitation' => true
                    ];
                }
            } else {
                return [
                    'text' => 'Sin Invitación',
                    'class' => 'danger',
                    'icon' => 'fas fa-times',
                    'is_collaborator' => false,
                    'has_pending_invitation' => false
                ];
            }
            
        } catch (\Exception $e) {
            error_log("Error verificando estado GitHub para {$student->usuario_github}: " . $e->getMessage());
            return [
                'text' => 'Error GitHub',
                'class' => 'secondary',
                'icon' => 'fas fa-exclamation-triangle',
                'is_collaborator' => false,
                'has_pending_invitation' => false
            ];
        }
    }

    //Verifica si una invitación está expirada (más de 7 días)
    private function isInvitationExpired(?string $created_at): bool {
        if (empty($created_at)) {
            return false;
        }
        
        try {
            $invitation_date = new \DateTime($created_at);
            $now = new \DateTime();
            $diff = $now->diff($invitation_date);
            
            // Considerar expirada si tiene más de 7 días
            return $diff->days > 7;
            
        } catch (\Exception $e) {
            error_log("Error calculando expiración de invitación: " . $e->getMessage());
            return false;
        }
    }

    // Determina si un usuario tiene una invitación pendiente
    public function determineHasPendingInvitation(?array $github_status_data, object $student): bool {
        // Si tenemos datos de GitHub válidos, usarlos
        if ($github_status_data !== null) {
            return $github_status_data['has_pending_invitation'] ?? false;
        }
        
        // Estado 1 = Invitación enviada, podría necesitar cancelación
        return in_array($student->invitacion_status, [1]);
    }

    //Actualiza el estado en BD basándose en el estado real de GitHub
    private function updateDatabaseStatusIfNeeded(object $student, bool $is_collaborator, bool $has_pending_invitation): void {
        global $DB;
        
        $current_status = $student->invitacion_status;
        $new_status = null;
        
        if ($is_collaborator && $current_status == 1) {
            // BD dice "enviada" pero GitHub dice "aceptada" -> actualizar a aceptado
            $new_status = 3;
            
        } elseif (!$is_collaborator && !$has_pending_invitation && $current_status == 1) {
            // BD dice "enviada" pero GitHub no tiene invitación pendiente ni es colaborador
            $new_status = 4; // Rechazado
        }
        
        // Actualizar BD si es necesario
        if ($new_status !== null) {
            $DB->set_field('usuarios_data_patroller', 'invitacion_status', $new_status, ['id' => $student->id]);
            $student->invitacion_status = $new_status;
        }
    }
}
