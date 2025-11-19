<?php
namespace mod_pluginpatroller\service\core;

use mod_pluginpatroller\model\GradeModel;

defined('MOODLE_INTERNAL') || die();

class GradebookService {
    
    /**
     * Crea o actualiza el grade item en el Gradebook de Moodle
     */
    public static function createOrUpdateGradeItem($pluginpatroller, $maxgrade = 10): bool {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        
        $params = [
            'itemname' => $pluginpatroller->name,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => $maxgrade,
            'grademin' => 0
        ];
        
        return grade_update(
            'mod/pluginpatroller',
            $pluginpatroller->course,
            'mod',
            'pluginpatroller',
            $pluginpatroller->id,
            0,
            null,
            $params
        ) === GRADE_UPDATE_OK;
    }
    
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
