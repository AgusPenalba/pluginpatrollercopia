<?php
namespace mod_pluginpatroller\model;

defined('MOODLE_INTERNAL') || die();

class GradeModel {
    /**
     * Obtiene la calificación numérica del alumno (campo 'calificacion').
     *
     * @param int $userId
     * @param int $courseId
     * @return string|null
     */
    public static function getStudentGradeValue(int $userId, int $courseId): ?string {
        global $DB;
        $grade = $DB->get_record('usuarios_data_patroller', [
            'id_usuario' => $userId,
            'id_materia' => $courseId
        ]);
        return $grade ? $grade->calificacion : null;
    }

    /**
     * Actualiza la calificación del alumno en la tabla personalizada.
     *
     * @param int $userId
     * @param int $courseId
     * @param string $grade Valor de la calificación (string o numérico)
     * @return bool True si se actualizó correctamente, false si no existe el registro
     */
    public static function updateStudentGrade(int $userId, int $courseId, string $grade): bool {
        global $DB;
        $record = $DB->get_record('usuarios_data_patroller', [
            'id_usuario' => $userId,
            'id_materia' => $courseId
        ]);
        if ($record) {
            $record->calificacion = $grade;
            return $DB->update_record('usuarios_data_patroller', $record);
        }
        return false;
    }
}
