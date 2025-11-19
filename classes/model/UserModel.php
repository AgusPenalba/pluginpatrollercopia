<?php
namespace mod_pluginpatroller\model;

defined('MOODLE_INTERNAL') || die();

class UserModel {

    /**
     * Obtiene los estudiantes asignados a un repositorio en un curso.
     *
     * @param int $repoid
     * @param int $courseid
     * @param bool $solo_activos
     * @return array
     */
    public static function get_students_by_repo(int $repoid, int $courseid, bool $solo_activos = false): array {
        global $DB;

        $conditions = ['id_repo' => $repoid, 'id_materia' => $courseid];
        if ($solo_activos) {
            $conditions['invitacion_status'] = 5; // Estado 'ACEPTADO'
        }

        return $DB->get_records('usuarios_data_patroller', $conditions) ?? [];
    }

    /**
     * Obtiene la sede del usuario según los grupos del curso.
     *
     * @param object $course
     * @param object $user
     * @return string|null
     */
    public static function get_sede_by_user(object $course, object $user): ?string {
        $groups = groups_get_all_groups($course->id, $user->id);
        foreach ($groups as $group) {
            $parts = explode('-', $group->name);
            if (count($parts) >= 2) {
                return $parts[0];
            }
        }
        return null;
    }

    /**
     * Obtiene el grupo del usuario según los grupos del curso.
     *
     * @param object $course
     * @param object $user
     * @return string|null
     */
    public static function get_grupo_by_user(object $course, object $user): ?string {
        $groups = groups_get_all_groups($course->id, $user->id);
        foreach ($groups as $group) {
            $parts = explode('-', $group->name);
            if (count($parts) >= 2) {
                return substr(end($parts), -1);
            }
        }
        return null;
    }

    /**
     * Obtiene todos los cursos (letras) disponibles en los grupos del curso.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_all_cursos_by_course_id(int $courseid): array {
        $groups = groups_get_all_groups($courseid);
        $cursos = [];

        foreach ($groups as $group) {
            $last_letter = substr($group->name, -1);
            $cursos[$last_letter] = $last_letter;
        }

        return $cursos;
    }

    /**
     * Inserta un nuevo alumno en la tabla si no existe.
     *
     * @param array $student
     * @return void
     */
    public static function insert_if_not_exists(array $student): void {
        global $DB;

        $exists = $DB->record_exists('usuarios_data_patroller', [
            'id_usuario' => $student['id_usuario'],
            'id_materia' => $student['id_materia']
        ]);

        if (!$exists) {
            $data = (object)[
                'nombre_usuario' => $student['nombre_usuario'],
                'mail_usuario' => $student['mail_usuario'],
                'id_usuario' => $student['id_usuario'],
                'id_materia' => $student['id_materia']
            ];
            $DB->insert_record('usuarios_data_patroller', $data);
        }
    }

    /**
     * Resetea los datos de commits de un alumno o de todos.
     *
     * @param int|null $id_usuario
     * @return void
     */
    public static function reset_commits(?int $id_usuario = null): void {
        global $DB;

        $fields = [
            'cantidad_commits' => 0,
            'lineas_agregadas' => 0,
            'lineas_eliminadas' => 0,
            'lineas_modificadas' => 0,
            'fecha_ultimo_commit' => null
        ];

        if ($id_usuario !== null) {
            $record = (object)array_merge(['id' => $id_usuario], $fields);
            $DB->update_record('usuarios_data_patroller', $record);
        } else {
            $students = $DB->get_records('usuarios_data_patroller');
            foreach ($students as $student) {
                foreach ($fields as $field => $value) {
                    $student->$field = $value;
                }
                $DB->update_record('usuarios_data_patroller', $student);
            }
        }
    }
}
