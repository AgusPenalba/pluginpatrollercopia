<?php
namespace mod_pluginpatroller\service\repository;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;
use mod_pluginpatroller\helpers\ConfigHelper;

/**
 * Servicio genérico de formateo de repositorios:
 * - Formatear datos de repositorios para tablas/UI
 * - Contar estudiantes por estado de invitación
 * - Generar clases CSS según estado de invitación
 * - Generar iconos según estado de invitación
 * - Generar mensajes de resumen de operaciones
 */
class RepositoryFormatterService {
    
    //Formatea los repositorios creados con estadísticas para el template del panel principal

    public function formatCreatedReposForMainPanel(array $users_by_repo): array {
        $created_repos = [];
        foreach ($users_by_repo as $repo_id => $repo) {
            $count_by_status = $this->countStudentsByInvitationStatus($repo['students']);
            
            // Construir repository_url usando helper centralizado
            $repository_url = ConfigHelper::buildRepositoryUrl($repo['nombre_repo'] ?? '');

            $created_repos[] = [
                'repo_id' => $repo_id,
                'sede' => htmlspecialchars($repo['sede']),
                'curso' => htmlspecialchars($repo['curso']),
                'nro' => htmlspecialchars($repo['nro']),
                'nombre_repo' => htmlspecialchars($repo['nombre_repo']),
                'total_members' => count($repo['students']),
                'accepted' => $count_by_status['ACCEPTED'] ?? 0,
                'pending' => $count_by_status['INVITATION_SENT'] ?? 0,
                'not_found' => 0,
                'missing_username' => 0,
                'unprocessed' => $count_by_status['UNPROCESSED'] ?? 0,
                'error' => $count_by_status['ERROR'] ?? 0,
                'repository_url' => $repository_url,
                'data_sede' => $repo['sede'] ?? '',
                'data_curso' => $repo['curso'] ?? '',
            ];
        }
        return $created_repos;
    }
    
    //Cuenta estudiantes por estado de invitación
    private function countStudentsByInvitationStatus(array $students): array {
        $counts = [];
        foreach ($students as $student) {
            $numeric_status = $student->invitacion_status ?? 0;
            
            // Mapear estados numéricos a texto
            $status = match((int)$numeric_status) {
                0 => 'UNPROCESSED',
                1 => 'INVITATION_SENT', 
                2 => 'ERROR',
                3 => 'ACCEPTED',
                default => 'UNPROCESSED'
            };
            
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        
        return $counts;
    }
    
    //Formatea los datos del repositorio para la vista de asignaciones de profesores
    public function formatRepoData($repo, int $status): array {
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
    
    //Genera mensaje de resumen de operaciones de asignación
    public function generateSummaryMessage(int $assigned, int $already_assigned, int $errors): string {
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
    
    //Obtiene la clase CSS Bootstrap para el badge según el estado
    public function getStatusBadgeClass(int $status): string {
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
    
    // Obtiene el icono FontAwesome según el estado
    public function getStatusIcon(int $status): string {
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
