<?php
namespace mod_pluginpatroller\service\student;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\helpers\UserHelper;
use mod_pluginpatroller\helpers\RoleHelper;

/**
 * Servicio especializado para operaciones con grupos de estudiantes
 * Extrae lógica compleja del MainPanelController
 */
class StudentGroupService {
    
    private $context;
    private $course;
    private $cm;
    
    public function __construct($context, $course, $cm) {
        $this->context = $context;
        $this->course = $course;
        $this->cm = $cm;
    }
    
    // Obtiene estudiantes agrupados por sede-curso con toda la lógica encapsulada
    public function getStudentsByCourseGroup(): array {
        $users_by_group = [];
        $all_enrolled_users = get_enrolled_users($this->context);

        foreach ($all_enrolled_users as $user) {
            $groups = $this->getUserGroupNamesInCourse($this->course->id, $user->id);
            
            foreach ($groups as $group) {
                $group_key = $this->parseGroupKey($group);
                if (!$group_key) continue;
                
                $this->initializeGroupIfNeeded($users_by_group, $group_key);
                $this->addUserToGroup($users_by_group, $group_key, $user);
            }
        }
        
        return $users_by_group;
    }
    
    //Sincroniza estudiantes de Moodle con la tabla personalizada
    public function syncStudentsToPatrollerTable(array $users_by_group): void {
        global $DB;
        
        foreach ($users_by_group as $g) {
            foreach ($g['students'] as $student) {
                if ($this->studentExistsInPatrollerTable($student)) {
                    continue;
                }
                
                $enriched_user = $this->getEnrichedUserData($student);
                if ($enriched_user) {
                    $this->insertStudentToPatrollerTable($enriched_user);
                }
            }
        }
    }
    
    //Calcula estadísticas de estudiantes pendientes por grupo
    public function calculateGroupStats(array $users_by_group, array $users_by_repo): array {
        $stats = [];
        
        foreach ($users_by_group as $group_key => $group) {
            $stats[] = [
                'group_name' => $group_key,
                'student_count' => count($group['students']),
                'teacher_count' => count($group['teachers']),
                'repos_created' => $this->countReposForGroup($users_by_repo, $group_key),
                'pending_students' => $this->countPendingStudents($group['students'], $users_by_repo),
            ];
        }
        
        return $stats;
    }
    
    // Métodos privados para encapsular lógica específica
    private function parseGroupKey(string $group): ?string {
        $parts = explode('-', $group);
        if (count($parts) > 1) {
            $sede = $parts[0];
            $course_letter = substr($group, -1);
            return $sede . '-' . $course_letter;
        }
        return null;
    }
    
    private function initializeGroupIfNeeded(array &$users_by_group, string $group_key): void {
        if (!isset($users_by_group[$group_key])) {
            $users_by_group[$group_key] = ['students' => [], 'teachers' => []];
        }
    }
    
    private function addUserToGroup(array &$users_by_group, string $group_key, $user): void {
        $entry = [
            'username' => $user->username,
            'id_usuario' => $user->id,
            'nombre_usuario' => $user->firstname . ' ' . $user->lastname,
            'mail_usuario' => $user->email,
            'id_materia' => $this->course->id,
        ];
        
        if (RoleHelper::isStudentByName($user->id, $this->context)) {
            $users_by_group[$group_key]['students'][] = $entry;
        } elseif (RoleHelper::isEditingTeacher($user->id, $this->context)) {
            $users_by_group[$group_key]['teachers'][] = $entry;
        }
    }
    
    private function getUserGroupNamesInCourse(int $courseid, int $userid): array {
        $groupnames = [];
        $groups = groups_get_all_groups($courseid, $userid);
        if (!empty($groups)) {
            foreach ($groups as $group) {
                $groupnames[] = $group->name;
            }
        }
        return $groupnames;
    }
    
    private function studentExistsInPatrollerTable(array $student): bool {
        global $DB;
        return $DB->record_exists('usuarios_data_patroller', [
            'id_usuario' => $student['id_usuario'],
            'id_materia' => $student['id_materia'],
        ]);
    }
    
    private function getEnrichedUserData(array $student): ?object {
        return UserHelper::getEnrichedUser($this->course, $student['id_usuario']);
    }
    
    private function insertStudentToPatrollerTable(object $user): void {
        global $DB;
        
        $DB->insert_record('usuarios_data_patroller', (object) [
            'nombre_usuario' => $user->firstname . ' ' . $user->lastname,
            'mail_usuario' => $user->email,
            'id_usuario' => $user->id,
            'id_materia' => $this->course->id,
            'sede' => $user->sede,
            'curso' => $user->curso,
            'id_repo' => 0,
            'usuario_github' => '',
            'cantidad_commits' => 0,
            'lineas_agregadas' => 0,
            'lineas_eliminadas' => 0,
            'lineas_modificadas' => 0,
            'fecha_ultimo_commit' => null,
            'invitacion_status' => 0,
        ]);
    }
    
    public function countReposForGroup(array $users_by_repo, string $group_key): int {
        [$sede, $curso] = UserHelper::splitGroupKey($group_key);
        $count = 0;
        foreach ($users_by_repo as $repo) {
            if ($repo['sede'] === $sede && $repo['curso'] === $curso) {
                $count++;
            }
        }
        return $count;
    }
    
    private function countPendingStudents(array $students, array $users_by_repo): int {
        $assigned_ids = $this->getAssignedStudentIds($users_by_repo);
        $pending = 0;
        
        foreach ($students as $student) {
            if (!in_array($student['id_usuario'], $assigned_ids)) {
                $pending++;
            }
        }
        
        return $pending;
    }
    
    private function getAssignedStudentIds(array $users_by_repo): array {
        $ids = [];
        foreach ($users_by_repo as $repo) {
            foreach ($repo['students'] as $s) {
                $ids[] = (int)$s->id_usuario;
            }
        }
        return $ids;
    }

    public function getTotalPendingStudents(array $users_by_group, array $users_by_repo): int {
        $total = 0;
        foreach ($users_by_group as $group_key => $users_in_course) {
            $total += $this->getPendingStudentsCountForGroup($users_by_group, $users_by_repo, $group_key);
        }
        return $total;
    }

    public function getPendingStudentsCountForGroup(array $users_by_group, array $users_by_repo, string $group_key): int {
        if (!isset($users_by_group[$group_key])) {
            return 0;
        }
        
        // Acceder a max_students_per_repo desde course settings
        global $DB;
        $plugin_instance = $DB->get_record('pluginpatroller', ['id' => $this->cm->instance]);
        $max_students_per_repo = $plugin_instance ? $plugin_instance->max_users_per_group : 10;
        
        return $this->calculatePendingWithCapacity(
            $users_by_group[$group_key]['students'], 
            $users_by_repo, 
            $group_key, 
            $max_students_per_repo
        );
    }
    private function calculatePendingWithCapacity(array $students, array $users_by_repo, string $group_key, int $max_students_per_repo): int {
        [$sede, $curso] = explode('-', $group_key);
        
        // Crear un mapa de IDs de estudiantes que REALMENTE están en este grupo (según Moodle)
        $current_group_student_ids = [];
        foreach ($students as $student) {
            $current_group_student_ids[$student['id_usuario']] = true;
        }
        
        // Contar repositorios existentes y estudiantes asignados QUE PERTENECEN AL GRUPO
        $existing_repos_count = 0;
        $assigned_student_ids = [];
        
        foreach ($users_by_repo as $repo) {
            if ($repo['sede'] === $sede && $repo['curso'] === $curso) {
                $existing_repos_count++;
                foreach ($repo['students'] as $assigned_student) {
                    // SOLO contar como asignado si el estudiante AÚN está en este grupo
                    if (isset($current_group_student_ids[$assigned_student->id_usuario])) {
                        $assigned_student_ids[$assigned_student->id_usuario] = true;
                    }
                }
            }
        }
        
        // Calcular capacidad total disponible
        $total_capacity = $existing_repos_count * $max_students_per_repo;
        $total_students = count($students);
        
        // Si la capacidad ya cubre a todos los estudiantes, no hay pendientes
        if ($total_capacity >= $total_students) {
            return 0;
        }
        
        // Contar estudiantes realmente sin asignar
        $unassigned_count = 0;
        foreach ($students as $student) {
            if (!array_key_exists($student['id_usuario'], $assigned_student_ids)) {
                $unassigned_count++;
            }
        }
        
        return $unassigned_count;
    }

    //Obtiene información del repositorio asignado al estudiante
    public function getRepositoryInfo(int $user_id): array {
        global $DB;
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
        
        $repo = $DB->get_record_sql($sql, [$user_id, $this->course->id]);
        
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

    //Obtiene los compañeros del mismo grupo
    public function getGroupClassmates($user, $sede, $curso): array {
        global $DB;
        
        // Si no se puede determinar la sede o curso, retornar array vacío
        if (!$sede || !$curso) {
            return [];
        }
        
        // Obtener todos los estudiantes del mismo grupo.
        $idrepo = $DB->get_field_sql("SELECT udp.id_repo FROM {usuarios_data_patroller} udp WHERE udp.id_usuario = ? AND udp.id_materia = ?", [$user->id, $this->course->id]);

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
            $is_current_user = ($student->id == $user->id);

            $classmates[] = [
                'id' => $student->id,
                'name' => $student->firstname . ' ' . $student->lastname,
                'github_username' => $student->usuario_github ?: 'No configurado',
                'repository_name' => $student->nombre_repo ?: get_string('unassigned', 'mod_pluginpatroller'),
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
            if ($c['id'] == $user->id) { $found_current = true; break; }
        }

        if (!$found_current) {
            // tratar de cargar datos del propio usuario desde la tabla usuarios_data_patroller
            $me = $DB->get_record('usuarios_data_patroller', ['id_usuario' => $user->id, 'id_materia' => $this->course->id]);
            if ($me) {
                $classmates[] = [
                    'id' => $user->id,
                    'name' => $user->firstname . ' ' . $user->lastname,
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
                    'id' => $user->id,
                    'name' => $user->firstname . ' ' . $user->lastname,
                    'github_username' => get_string('notconfigured', 'mod_pluginpatroller'),
                    'repository_name' => '',
                    'repository_number' => '',
                    'invitation_status' => get_string('status_unprocessed', 'mod_pluginpatroller'),
                    'invitation_status_class' => 'badge-secondary',
                    'is_current_user' => true,
                    'is_github_configured' => false,
                    'has_repository' => false
                ];
            }
        }

        return $classmates;
    }

    //Obtiene el texto del estado de invitación
    private function getInvitationStatusText(int $status): string {
        return match($status) {
            0 => get_string('status_unprocessed', 'mod_pluginpatroller'),
            1 => get_string('status_sent', 'mod_pluginpatroller'),
            2 => get_string('status_error', 'mod_pluginpatroller'),
            3 => get_string('status_accepted', 'mod_pluginpatroller'),
            default => get_string('status_unknown', 'mod_pluginpatroller')
        };
    }
    
    //Obtiene la clase CSS para el estado de invitación
    private function getInvitationStatusClass(int $status): string {
        return match($status) {
            0 => 'badge-secondary',
            1 => 'badge-warning',
            2 => 'badge-danger',
            3 => 'badge-success',
            default => 'badge-secondary'
        };
    }

    // Obtiene estadísticas del grupo
    public function getGroupStatistics(array $classmates): array {
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
}
