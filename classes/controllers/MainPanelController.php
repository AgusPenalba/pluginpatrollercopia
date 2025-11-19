<?php

namespace mod_pluginpatroller\controllers;

use mod_pluginpatroller\model\UserModel;
use mod_pluginpatroller\helpers\UserHelper;
use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\service\analytics\StatisticsService;
use mod_pluginpatroller\service\view\TemplateDataService;
use mod_pluginpatroller\service\student\StudentGroupService;
use mod_pluginpatroller\service\github\GitHubApiService;
use mod_pluginpatroller\service\repository\RepositoryCreationService;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;
use mod_pluginpatroller\service\repository\RepositoryFormatterService;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/weblib.php');

/*Controla todo el flujo del panel principal
* - Verifica permisos usando helper optimizado
* - Carga estudiantes por grupos 
* - Sincroniza base de datos
* - Maneja creación de repositorios
* - Prepara datos para mostrar 
*/
class MainPanelController extends AbstractController {
    
    private $studentGroupService;
    private $repoCreationService;
    private $statisticsService;
    private $repoFormatterService;
    private $templateDataService;

    public function execute(): string {
        $this->requireTeacherPermissions();
        $this->initializeServices();

        try {
            if ($this->getParam('delete_repo_id')) {
                $this->handleRepositoryDeletion();
            }

            $data = $this->loadCommonData();
            
            if ($this->getParam('create_repos_submit')) {
                $this->handleRepositoryCreation($data['users_by_repo']);
            }

            return $this->render('main_panel', $data['template_data']);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    //Renderiza sólo la vista de estadísticas para admin
    public function executeStatistics(): string {
        $this->requireTeacherPermissions();
        $this->initializeServices();

        try {
            $data = $this->loadCommonData();
            return $this->render('statistics', $data['template_data']);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    //Inicializa los servicios especializados
    private function initializeServices(): void {
        $githubAPI = (new GitHubApiService())->getAPIOrFail();
        
        $this->studentGroupService = new StudentGroupService($this->context, $this->course, $this->cm);
        $this->repoCreationService = new RepositoryCreationService($this->course, $githubAPI);
        $this->statisticsService = new StatisticsService();
        $this->repoFormatterService = new RepositoryFormatterService();
        $this->templateDataService = new TemplateDataService($this->course, $this->cm);
    }

    //Carga datos comunes para ambas vistas (main_panel y statistics)
    private function loadCommonData(): array {
        $max_students_per_repo = $this->pluginpatroller->max_users_per_group;
        $users_by_group = $this->studentGroupService->getStudentsByCourseGroup();
        $this->studentGroupService->syncStudentsToPatrollerTable($users_by_group);
        
        // DISABLED: Actualizar estados de invitaciones automáticamente
        // $this->updateInvitationStatuses();
        
        $users_by_repo = $this->repoCreationService->getReposWithNestedStudents();
        
        return [
            'users_by_group' => $users_by_group,
            'users_by_repo' => $users_by_repo,
            'template_data' => $this->prepareTemplateData($users_by_group, $users_by_repo, $max_students_per_repo)
        ];
    }
    
    //Maneja la creación de repositorios con redirección
    private function handleRepositoryCreation(array $users_by_repo): void {
        $selected_groups = $this->getParamArray('selected_groups');
        $repos_to_add = $this->getParamArray('repos_to_add');
        
        $this->repoCreationService->processCreateRepos($selected_groups, $repos_to_add, $users_by_repo);
        $this->redirect($this->getPluginUrl(), get_string('repositoriescreatedsuccessfully', 'mod_pluginpatroller'), 3);
    }

    //Organiza toda la información para el template usando servicios optimizados
    private function prepareTemplateData(array $users_by_group, array $users_by_repo, int $max_students_per_repo): array {
        global $COURSE, $USER;
        
        $filter_config = [
            'show_name' => true,
            'show_sede' => true,  
            'show_curso' => true,  
            'show_repo' => false   
        ];
        
        $filters_data = $this->templateDataService->prepareFiltersConfig($filter_config);
        $table_id = 'created_repos_table_' . $this->cm->id;
        
        $parts = explode('-', $COURSE->shortname);
        $year = isset($parts[1]) ? $parts[1] : '';
        $semester = isset($parts[2]) ? $parts[2] : '';
        
        $total_pending = $this->studentGroupService->getTotalPendingStudents($users_by_group, $users_by_repo);// Calcular total de pendientes
        $pending_groups_data = $this->templateDataService->preparePendingGroupsMatrix($users_by_group, $users_by_repo, $max_students_per_repo, $this->studentGroupService);// Preparar datos matriciales para alumnos pendientes (funcionalidad original)
        $created_repos_data = $this->repoFormatterService->formatCreatedReposForMainPanel($users_by_repo);// Preparar repositorios creados con estadísticas detalladas (funcionalidad original)
        $repo_stats = $this->statisticsService->calculatePerRepo($users_by_repo);// Estadísticas por repositorio para comparativas en admin

        //Construir arrays para Chart.js combinando todos los estudiantes de todos los repos (centralizado)
        $chartArrays = $this->statisticsService->chartArraysFromRepos($users_by_repo);
        $chart_labels = $chartArrays['labels'] ?? [];
        $chart_commits = $chartArrays['commits'] ?? [];
        $chart_lines = $chartArrays['lines'] ?? [];
        $chart_lines_added = $chartArrays['lines_added'] ?? [];
        $chart_lines_deleted = $chartArrays['lines_deleted'] ?? [];
        $chart_lines_modified = $chartArrays['lines_modified'] ?? [];

        //Preparar datos por repositorio (repos == grupos de repos)
        $groupData = $this->statisticsService->prepareGroupOptions($users_by_repo);
        $group_options = $groupData['group_options'];
        $first_group_key = $groupData['first_group_key'];
        $group_chart_map = $groupData['group_chart_map'];

        //Agregados por grupo (suma por repo)
        $groupedChartData = $this->statisticsService->prepareGroupedChartData($users_by_repo);
        $groups_labels = $groupedChartData['groups_labels'];
        $groups_commits = $groupedChartData['groups_commits'];
        $groups_lines_added = $groupedChartData['groups_lines_added'];
        $groups_lines_deleted = $groupedChartData['groups_lines_deleted'];
        $groups_lines_modified = $groupedChartData['groups_lines_modified'];

        return [
            // Datos para el page_header component
            'header_icon' => 'fas fa-code-branch',
            'header_title' => get_string('repositorymanagement', 'mod_pluginpatroller'),
            'header_subtitle' => get_string('githubreposadmin', 'mod_pluginpatroller'),
            'course_name' => htmlspecialchars($COURSE->fullname),
            'user_fullname' => htmlspecialchars($USER->firstname . ' ' . $USER->lastname),
            
            // Datos básicos del curso (tabla de cabecera original)
            'course_info' => [
                'shortname' => htmlspecialchars($COURSE->shortname),
                'year' => htmlspecialchars($year),
                'semester' => htmlspecialchars($semester),
                'total_pending' => $total_pending,
                'max_students_per_repo' => $max_students_per_repo
            ],
            
            // Datos para formulario matricial (funcionalidad original)
            'cm_id' => $this->cm->id,
            'sesskey' => $USER->sesskey ?? '',
            'has_pending_groups' => !empty($pending_groups_data),
            'course_letters' => $this->getCourseLetters($users_by_group),
            'sedes' => $pending_groups_data,
            
            // Repositorios creados (funcionalidad original)
            'has_created_repos' => !empty($created_repos_data),
            'created_repos' => $created_repos_data,
            // Estadísticas por repositorio para la vista admin
            'repo_stats' => $repo_stats,
            // Arrays JSON para Chart.js (admin comparativa)
            'chart_labels_json' => json_encode($chart_labels),
            'chart_commits_json' => json_encode($chart_commits),
            'chart_lines_json' => json_encode($chart_lines),
            'chart_lines_added_json' => json_encode($chart_lines_added),
            'chart_lines_deleted_json' => json_encode($chart_lines_deleted),
            'chart_lines_modified_json' => json_encode($chart_lines_modified),
            // datos por repo (grupos)
            'group_options' => $group_options,
            'first_group_key' => $first_group_key,
            'group_chart_map_json' => json_encode($group_chart_map),
            // agregados por repo
            'groups_labels_json' => json_encode($groups_labels),
            'groups_commits_json' => json_encode($groups_commits),
            'groups_lines_added_json' => json_encode($groups_lines_added),
            'groups_lines_deleted_json' => json_encode($groups_lines_deleted),
            'groups_lines_modified_json' => json_encode($groups_lines_modified),
            
            // Configuración de filtros para la tabla de repositorios creados
            'filters_config' => true,
            'filters' => is_array($filters_data) && isset($filters_data['filters']) ? $filters_data['filters'] : [],
            'filter_script' => is_array($filters_data) && isset($filters_data['script_functions']) ? $filters_data['script_functions'] : '',
            'table_id' => $table_id,
        ];
    }
    
    //Obtiene las letras de curso únicas
    private function getCourseLetters(array $users_by_group): array {
        $letters = [];
        foreach ($users_by_group as $group_key => $students_in_course) {
            $parts = explode('-', $group_key);
            if (count($parts) >= 2) {
                $letters[] = $parts[1]; // La letra del curso
            }
        }
        return array_unique($letters);
    }

    //Maneja la eliminación de repositorios
    private function handleRepositoryDeletion(): void {
        $repo_id = (int)$this->getParam('delete_repo_id');
        
        $result = $this->repoCreationService->deleteRepository($repo_id, $this->course->id);
        
        if ($result['success']) {
            $this->redirect($this->getPluginUrl(), $result['message'], 3);
        } else {
            throw new \Exception($result['message']);
        }
    }

}