<?php
namespace mod_pluginpatroller\controllers;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\service\StudentGroupService;
use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\model\UserModel;
use mod_pluginpatroller\service\ContributorInsightsService;
use mod_pluginpatroller\helpers\FilterHelper;

/**
 * Controlador para la funcionalidad de grupos de estudiantes
 * Muestra información detallada del grupo del estudiante
 */
class GroupController extends AbstractController {
    
    private StudentGroupService $studentGroupService;
    
    public function __construct($context, $course, $cm, $pluginpatroller) {
        parent::__construct($context, $course, $cm, $pluginpatroller);
        $this->studentGroupService = new StudentGroupService($this->context, $this->course);
    }
    
    /**
     * Ejecuta la lógica principal del controlador de grupos
     */
    public function execute(): string {
        $this->isStudent();
        
        try {
            $data = $this->prepareGroupData();
            return $this->render('grupo', $data);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }
    
    /**
     * Prepara los datos para mostrar la información del grupo
     */
    private function prepareGroupData(): array {
        global $USER, $COURSE;
        
        // Obtener información del grupo del estudiante
        $groupInfo = $this->getStudentGroupInfo();
        
        // Obtener compañeros del mismo grupo
        $classmates = $this->getGroupClassmates();
        
        // Obtener información del repositorio asignado
        $repositoryInfo = $this->getRepositoryInfo();
        
        // Obtener estadísticas del grupo
        $groupStats = $this->getGroupStatistics($classmates);

        // Preparar datos para el panel de seguimiento y calificaciones (vista simplificada para estudiante)
        global $DB;
        $idrepo = $DB->get_field_sql("SELECT udp.id_repo FROM {usuarios_data_patroller} udp WHERE udp.id_usuario = ? AND udp.id_materia = ?", [$USER->id, $this->course->id]);
        $students_data = [];
        $students_raw = [];
        if ($idrepo) {
            $contribService = new ContributorInsightsService($this->course, $this->pluginpatroller);
            $students_data = $contribService->getStudentsCommitData((int)$idrepo);
            // fetch raw DB rows for debugging (unformatted)
            $students_raw = $DB->get_records('usuarios_data_patroller', ['id_repo' => $idrepo, 'id_materia' => $this->course->id]);
            // Marcar la fila del usuario actual
            foreach ($students_data as &$sd) {
                $sd['is_current_user'] = (isset($sd['student_id']) && $sd['student_id'] == $USER->id);
            }
            unset($sd);
        }

        // Optional: allow on-demand recalculation via ?recalculate=1 for users with manage capability
        $success_message = null;
        $can_update_commits = has_capability('mod/pluginpatroller:manage', $this->context);
        $recalculate = optional_param('recalculate', 0, PARAM_INT);
        if ($recalculate && $can_update_commits) {
            try {
                $commitService = new \mod_pluginpatroller\service\CommitService();
                $commitService->updateCommits($this->course->id);
                $success_message = 'Recalculado correctamente.';
            } catch (\Exception $e) {
                // minimal logging for maintenance
                error_log('[pluginpatroller] Recalculate failed');
            }
        }

        // Preparar datos para los gráficos (labels y series)
        $chart_labels = [];
        $chart_commits = [];
        $chart_lines = [];
        if (!empty($students_data)) {
            $statsService = new \mod_pluginpatroller\service\StatisticsService();
            $chartArrays = $statsService->chartArraysFromStudents($students_data);
            $chart_labels = $chartArrays['labels'] ?? [];
            $chart_commits = $chartArrays['commits'] ?? [];
            $chart_lines = $chartArrays['lines'] ?? [];
            $chart_lines_added = $chartArrays['lines_added'] ?? [];
            $chart_lines_deleted = $chartArrays['lines_deleted'] ?? [];
            $chart_lines_modified = $chartArrays['lines_modified'] ?? [];
        }
        // Construir URL del repositorio con lógica defensiva:
        // 1) Si el controlador ya trae 'url' en repositoryInfo, usarla.
        // 2) Si repositoryInfo.name contiene una barra (owner/repo) usarla tal cual.
        // 3) En otro caso, usar el owner configurado via ConfigHelper.
        $repository_url = '';
        if (!empty($repositoryInfo['url'])) {
            // Ya viene la URL completa desde la fuente de datos
            $repository_url = $repositoryInfo['url'];
        } else {
            $owner = \mod_pluginpatroller\helpers\ConfigHelper::getGitHubOwner();
            if (!empty($repositoryInfo['name'])) {
                $name = $repositoryInfo['name'];
                // Si el nombre ya incluye owner (p.ej. owner/repo), usarlo directamente
                if (strpos($name, '/') !== false) {
                    $repository_url = 'https://github.com/' . rawurlencode($name);
                } elseif (!empty($owner)) {
                    // Construir con el owner configurado
                    $repository_url = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($name);
                }
            }
        }
        // Log chart arrays for debugging before rendering template
        // Debug logging removed in cleanup.

        return [
            // Header para vista de grupo
            'header_icon' => 'fas fa-users-cog',
            'header_title' => 'Mi Grupo',
            'header_subtitle' => 'Información detallada de tu grupo académico',
            'course_name' => htmlspecialchars($COURSE->fullname),
            'user_fullname' => htmlspecialchars($USER->firstname . ' ' . $USER->lastname),
            
            // Información del grupo
            'group_info' => $groupInfo,
            
            // Compañeros del grupo
            'classmates' => $classmates,
            'has_classmates' => !empty($classmates),
            'total_classmates' => count($classmates),
            
            // Información del repositorio
            'repository_info' => $repositoryInfo,
            'repository_url' => $repository_url,
            'has_repository' => !empty($repositoryInfo),
            
            // Estadísticas del grupo
            'group_stats' => $groupStats,

            // Datos para el panel de seguimiento y calificaciones
            'students' => array_values($students_data),
            'has_students' => !empty($students_data),
            'can_update_commits' => $can_update_commits,
            'success_message' => $success_message,
            'filter_script' => FilterHelper::getFilterScriptFunctions(),

            // Datos para gráficos
            'chart_labels_json' => json_encode($chart_labels),
            'chart_commits_json' => json_encode($chart_commits),
            'chart_lines_json' => json_encode($chart_lines),
            'chart_lines_added_json' => json_encode($chart_lines_added),
            'chart_lines_deleted_json' => json_encode($chart_lines_deleted),
            'chart_lines_modified_json' => json_encode($chart_lines_modified),
            // students_raw_json removed from template payload for privacy/cleanup

            // Variables necesarias para el template
            'sesskey' => sesskey(),
            'cm_id' => $this->cm->id
        ];
    }
    
    /**
     * Obtiene la información básica del grupo del estudiante
     */
    private function getStudentGroupInfo(): array {
        global $USER;
        
        $sede = $this->getSedeByUser($USER) ?? 'No asignado';
        $curso = $this->getGrupoByUser($USER) ?? 'No asignado';
        
        return [
            'sede' => $sede,
            'curso' => $curso,
            'group_key' => $sede . '-' . $curso,
            'display_name' => $sede . ' - ' . $curso
        ];
    }
    
    /**
     * Obtiene los compañeros del mismo grupo
     */
    private function getGroupClassmates(): array {
        global $USER, $DB;
        
        $sede = $this->getSedeByUser($USER);
        $curso = $this->getGrupoByUser($USER);
        
        // Si no se puede determinar la sede o curso, retornar array vacío
        if (!$sede || !$curso) {
            return [];
        }
        
    // Obtener todos los estudiantes del mismo grupo. Algunas instalaciones no tienen las columnas
    // "sede"/"curso" en la tabla usuarios_data_patroller, por lo que aplicamos el filtro
    // a partir de los datos del repositorio (tabla repositorios_data_patroller) donde sí existen.
        // Primero, obtener el id_repo asignado al usuario en esta materia (si existe)
        $idrepo = $DB->get_field_sql("SELECT udp.id_repo FROM {usuarios_data_patroller} udp WHERE udp.id_usuario = ? AND udp.id_materia = ?", [$USER->id, $this->course->id]);

        if (!$idrepo) {
            // Si no hay repositorio asignado al usuario, no mostramos otros miembros (solo el propio usuario)
            $students = [];
        } else {
            // Obtener solo los miembros activos del mismo repositorio (invitacion_status = 3)
            $sql = "SELECT u.id, u.firstname, u.lastname, udp.usuario_github, udp.invitacion_status, 
                           r.nombre_repo, r.num_grupo
                    FROM {usuarios_data_patroller} udp
                    JOIN {user} u ON udp.id_usuario = u.id
                    LEFT JOIN {repositorios_data_patroller} r ON udp.id_repo = r.id
                    WHERE udp.id_materia = ? 
                    AND udp.id_repo = ?
                    AND udp.invitacion_status = 3
                    ORDER BY u.firstname, u.lastname";

            $students = $DB->get_records_sql($sql, [$this->course->id, $idrepo]);
        }
        
        $classmates = [];
        foreach ($students as $student) {
            $is_current_user = ($student->id == $USER->id);

            $classmates[] = [
                'id' => $student->id,
                'name' => $student->firstname . ' ' . $student->lastname,
                'github_username' => $student->usuario_github ?: 'No configurado',
                'repository_name' => $student->nombre_repo ?: 'Sin asignar',
                'repository_number' => $student->num_grupo ?: 'N/A',
                'invitation_status' => $this->getInvitationStatusText($student->invitacion_status),
                'invitation_status_class' => $this->getInvitationStatusClass($student->invitacion_status),
                'is_current_user' => $is_current_user,
                'is_github_configured' => !empty($student->usuario_github),
                'has_repository' => !empty($student->nombre_repo)
            ];
        }
        // Asegurar que el usuario actual aparezca en la lista aunque no sea miembro activo (p. ej. aún no aceptó)
        $found_current = false;
        foreach ($classmates as $c) {
            if ($c['id'] == $USER->id) { $found_current = true; break; }
        }

        if (!$found_current) {
            // tratar de cargar datos del propio usuario desde la tabla usuarios_data_patroller
            $me = $DB->get_record('usuarios_data_patroller', ['id_usuario' => $USER->id, 'id_materia' => $this->course->id]);
            if ($me) {
                $classmates[] = [
                    'id' => $USER->id,
                    'name' => $USER->firstname . ' ' . $USER->lastname,
                    'github_username' => $me->usuario_github ?: 'No configurado',
                    'repository_name' => '',
                    'repository_number' => '',
                    'invitation_status' => $this->getInvitationStatusText($me->invitacion_status),
                    'invitation_status_class' => $this->getInvitationStatusClass($me->invitacion_status),
                    'is_current_user' => true,
                    'is_github_configured' => !empty($me->usuario_github),
                    'has_repository' => !empty($me->id_repo)
                ];
            } else {
                // Si no existen datos, añadimos al menos su entrada básica
                $classmates[] = [
                    'id' => $USER->id,
                    'name' => $USER->firstname . ' ' . $USER->lastname,
                    'github_username' => 'No configurado',
                    'repository_name' => '',
                    'repository_number' => '',
                    'invitation_status' => 'Sin procesar',
                    'invitation_status_class' => 'badge-secondary',
                    'is_current_user' => true,
                    'is_github_configured' => false,
                    'has_repository' => false
                ];
            }
        }

        
        return $classmates;
    }
    
    /**
     * Obtiene información del repositorio asignado al estudiante
     */
    private function getRepositoryInfo(): array {
        global $USER, $DB;
        
        $sql = "SELECT r.nombre_repo, r.num_grupo, r.sede, r.curso,
                       COUNT(udp.id) as total_members,
                       SUM(CASE WHEN udp.invitacion_status = 3 THEN 1 ELSE 0 END) as active_members
                FROM {repositorios_data_patroller} r
                LEFT JOIN {usuarios_data_patroller} udp ON r.id = udp.id_repo
                WHERE r.id = (
                    SELECT udp2.id_repo 
                    FROM {usuarios_data_patroller} udp2 
                    WHERE udp2.id_usuario = ? AND udp2.id_materia = ?
                )
                GROUP BY r.id, r.nombre_repo, r.num_grupo, r.sede, r.curso";
        
        $repo = $DB->get_record_sql($sql, [$USER->id, $this->course->id]);
        
        if (!$repo) {
            return [];
        }
        
        return [
            'name' => $repo->nombre_repo,
            'number' => $repo->num_grupo,
            'sede' => $repo->sede,
            'curso' => $repo->curso,
            'total_members' => $repo->total_members,
            'active_members' => $repo->active_members,
            'pending_members' => $repo->total_members - $repo->active_members
        ];
    }
    
    /**
     * Obtiene estadísticas del grupo
     */
    private function getGroupStatistics(array $classmates): array {
        $total_students = count($classmates);
        $github_configured = 0;
        $repositories_assigned = 0;
        $active_members = 0;
        
        foreach ($classmates as $classmate) {
            if ($classmate['is_github_configured']) {
                $github_configured++;
            }
            if ($classmate['has_repository']) {
                $repositories_assigned++;
            }
            if ($classmate['invitation_status'] === 'Aceptado') {
                $active_members++;
            }
        }
        
        return [
            'total_students' => $total_students,
            'github_configured' => $github_configured,
            'repositories_assigned' => $repositories_assigned,
            'active_members' => $active_members,
            'pending_members' => $total_students - $active_members,
            'github_percentage' => $total_students > 0 ? round(($github_configured / $total_students) * 100, 1) : 0,
            'active_percentage' => $total_students > 0 ? round(($active_members / $total_students) * 100, 1) : 0
        ];
    }
    
    /**
     * Obtiene el texto del estado de invitación
     */
    private function getInvitationStatusText(int $status): string {
        return match($status) {
            0 => 'Sin procesar',
            1 => 'Invitación enviada',
            2 => 'Error',
            3 => 'Aceptado',
            default => 'Desconocido'
        };
    }
    
    /**
     * Obtiene la clase CSS para el estado de invitación
     */
    private function getInvitationStatusClass(int $status): string {
        return match($status) {
            0 => 'badge-secondary',
            1 => 'badge-warning',
            2 => 'badge-danger',
            3 => 'badge-success',
            default => 'badge-secondary'
        };
    }
    
    /**
     * Obtiene la sede del usuario usando UserModel
     */
    private function getSedeByUser($user): ?string {
        return UserModel::get_sede_by_user($this->course, $user);
    }
    
    /**
     * Obtiene el grupo del usuario usando UserModel
     */
    private function getGrupoByUser($user): ?string {
        return UserModel::get_grupo_by_user($this->course, $user);
    }
}
