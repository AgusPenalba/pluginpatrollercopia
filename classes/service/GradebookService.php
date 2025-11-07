<?php
namespace mod_pluginpatroller\service;

use mod_pluginpatroller\model\GradeModel;

defined('MOODLE_INTERNAL') || die();

class GradebookService {
    public static function getStudentGrade(int $userid, int $courseid): ?string {
        return GradeModel::getStudentGradeValue($userid, $courseid);
    }
    public static function updateStudentGrade(int $userid, int $courseid, $grade): void {
        GradeModel::updateStudentGrade($userid, $courseid, (string)$grade);

        // Si quieres sincronizar con el Gradebook de Moodle, descomenta lo siguiente:
        /*
        $gradeItem = GradeModel::getGradeItem($courseid, $itemname);

        if (!$gradeItem) {
            return; // No se encontró el ítem de calificación
        }

        $gradeData = new \stdClass();
        $gradeData->userid = $userid;
        $gradeData->rawgrade = is_null($grade) ? null : (float)$grade;

        grade_update(
            'mod/pluginpatroller',
            $gradeItem->course,
            'mod',
            'pluginpatroller',
            $gradeItem->id,
            0,
            [$userid => $gradeData]
        );
        */

    }
}
