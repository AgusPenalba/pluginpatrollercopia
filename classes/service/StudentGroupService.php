<?php
namespace mod_pluginpatroller\service;

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
    
    public function __construct($context, $course) {
        $this->context = $context;
        $this->course = $course;
    }
    
    /**
     * Obtiene estudiantes agrupados por sede-curso con toda la lógica encapsulada
     */
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
    
    /**
     * Sincroniza estudiantes de Moodle con la tabla personalizada
     */
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
    
    /**
     * Calcula estadísticas de estudiantes pendientes por grupo
     */
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
    
    private function countReposForGroup(array $users_by_repo, string $group_key): int {
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
}
