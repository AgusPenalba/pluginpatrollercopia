<?php
namespace mod_pluginpatroller\controllers;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\service\student\StudentGroupService;
use mod_pluginpatroller\service\view\TemplateDataService;
use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\model\UserModel;
use mod_pluginpatroller\service\analytics\ContributorInsightsService;
use mod_pluginpatroller\helpers\FilterHelper;

/**
 * Controlador para la funcionalidad de grupos de estudiantes
 * Muestra información detallada del grupo del estudiante
 */
class GroupController extends AbstractController {
    
    private StudentGroupService $studentGroupService;
    private TemplateDataService $templateDataService;
    
    public function __construct($context, $course, $cm, $pluginpatroller) {
        parent::__construct($context, $course, $cm, $pluginpatroller);
        $this->studentGroupService = new StudentGroupService($this->context, $this->course, $this->cm);
        $this->templateDataService = new TemplateDataService( $this->course, $this->cm);
    }
    
    //Ejecuta la lógica principal del controlador de grupos
    public function execute(): string {
        $this->isStudent();
        
        try {
            $data = $this->prepareGroupData();
            return $this->render('grupo', $data);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }
    
    //Prepara los datos para mostrar la información del grupo
    private function prepareGroupData(): array {
        // Obtener sede y curso una sola vez
        $sede = $this->getSedeByUser($this->user) ?? 'No asignado';
        $curso = $this->getGrupoByUser($this->user) ?? 'No asignado';
        
        // Información del grupo del estudiante
        $groupInfo = [
            'sede' => $sede,
            'curso' => $curso,
            'group_key' => $sede . '-' . $curso,
            'display_name' => $sede . ' - ' . $curso
        ];
        
        $classmates = $this->studentGroupService->getGroupClassmates($this->user, $sede, $curso);// Obtener compañeros del mismo grupo
        $repositoryInfo = $this->studentGroupService->getRepositoryInfo($this->user->id);// Obtener información del repositorio asignado
        $groupStats = $this->studentGroupService->getGroupStatistics($classmates);// Obtener estadísticas del grupo


        // Preparar datos de commits y estadísticas
        $contribService = new ContributorInsightsService($this->course, $this->pluginpatroller);
        $students_data = $contribService->getCommitDataForUser($this->user->id);
        $success_message = $this->handleCommitRecalculation();
        $can_update_commits = has_capability('mod/pluginpatroller:manage', $this->context);
        $statsService = new \mod_pluginpatroller\service\analytics\StatisticsService();
        $chartData = $statsService->prepareChartData($students_data);

        // Construir URL del repositorio
        $repository_url = !empty($repositoryInfo['url']) 
            ? $repositoryInfo['url'] 
            : ConfigHelper::buildRepositoryUrl($repositoryInfo['name'] ?? '');

        $headerData = $this->templateDataService->preparePageHeader(
            'fas fa-users-cog',
            get_string('group', 'mod_pluginpatroller'),
            get_string('group_header_subtitle', 'mod_pluginpatroller')
        );

        return array_merge($headerData, [
            // Información del grupo
            'group_info' => $groupInfo,
            
            'classmates' => $classmates,
            'has_classmates' => !empty($classmates),

            'label_lines_added' => get_string('linesadded', 'mod_pluginpatroller'),
            'label_lines_deleted' => get_string('linesdeleted', 'mod_pluginpatroller'),
            'label_lines_modified' => get_string('linesmodified', 'mod_pluginpatroller'),
            'label_commits' => get_string('commits', 'mod_pluginpatroller'),
            'total_classmates' => count($classmates),
            
            'repository_info' => $repositoryInfo,
            'repository_url' => $repository_url,
            'has_repository' => !empty($repositoryInfo),

            'group_stats' => $groupStats,

            'students' => array_values($students_data),
            'has_students' => !empty($students_data),
            'can_update_commits' => $can_update_commits,
            'success_message' => $success_message,
            'filter_script' => FilterHelper::getFilterScriptFunctions(),

            'chart_labels_json' => json_encode($chartData['labels']),
            'chart_commits_json' => json_encode($chartData['commits']),
            'chart_lines_json' => json_encode($chartData['lines']),
            'chart_lines_added_json' => json_encode($chartData['lines_added']),
            'chart_lines_deleted_json' => json_encode($chartData['lines_deleted']),
            'chart_lines_modified_json' => json_encode($chartData['lines_modified']),

            'sesskey' => sesskey(),
            'cm_id' => $this->cm->id
        ]);
    }
    
    //Maneja la recalculación de commits bajo demanda
    private function handleCommitRecalculation(): ?string {
        $can_update_commits = has_capability('mod/pluginpatroller:manage', $this->context);
        $recalculate = optional_param('recalculate', 0, PARAM_INT);
        if ($recalculate && $can_update_commits) {
            try {
                $commitService = new \mod_pluginpatroller\service\github\CommitService();
                $commitService->updateCommits($this->course->id);
                return 'Recalculado correctamente.';
            } catch (\Exception $e) {
                error_log('[pluginpatroller] Recalculate failed');
            }
        }
        return null;
    }

    //Obtiene la sede del usuario usando UserModel
    private function getSedeByUser($user): ?string {
        return UserModel::get_sede_by_user($this->course, $user);
    }
    
    //Obtiene el grupo del usuario usando UserModel
    private function getGrupoByUser($user): ?string {
        return UserModel::get_grupo_by_user($this->course, $user);
    }
}
