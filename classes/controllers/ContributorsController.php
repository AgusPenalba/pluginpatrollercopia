<?php
namespace mod_pluginpatroller\controllers;

use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\service\core\GradebookService;
use mod_pluginpatroller\service\analytics\ContributorInsightsService;
use mod_pluginpatroller\service\view\TemplateDataService;

//Controller para seguimiento de commits y calificaciones
class ContributorsController extends AbstractController {
    
    private ContributorInsightsService $contributorService;
    private GradebookService $gradebookService;
    private TemplateDataService $templateDataService;
    
    public function __construct($context, $course, $cm, $pluginpatroller) {
        parent::__construct($context, $course, $cm, $pluginpatroller);
        $this->initializeServices();
    }
    
    //Procesa actualización de commits y guardado de calificaciones
    public function execute(): string {
        try {
            // Procesar actualizaciones si se solicitó
            $updateParam = $this->getParam('update');
            if ($updateParam == 1 || $updateParam === 'true') {
                $this->updateCommits();
            }
            
            // Procesar guardado de calificaciones
            if ($this->getParam('guardarCalificaciones')) {
                $this->processSaveGrades();
            }
            
            // Preparar datos y renderizar template
            $data = $this->prepareTemplateData();
            
            return $this->render('contributors_insights', $data);
            
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }
    
    //Inicializa servicios especializados
    private function initializeServices(): void {
        $this->contributorService = new ContributorInsightsService($this->course, $this->pluginpatroller);
        $this->gradebookService = new GradebookService();
        $this->templateDataService = new TemplateDataService($this->course, $this->cm);
    }
    
    //Actualiza los datos de commits usando CommitService
    private function updateCommits(): void {
        $this->contributorService->updateCommits();
    }
    
    //Procesa el guardado de calificaciones usando servicios
    private function processSaveGrades(): void {
        $calificaciones = optional_param_array('calificacion', [], PARAM_RAW);
        $this->contributorService->processSaveGrades($calificaciones);
    }
    
    //Prepara todos los datos para el template usando servicios especializados
    private function prepareTemplateData(): array {
        $owner = ConfigHelper::getGitHubOwner();
        $repo_filter = $this->getParam('filterRepo', 'All');
        
        // Convertir filtro a int si no es 'All'
        $repo_filter_int = ($repo_filter === 'All') ? null : (int)$repo_filter;
        
        // Obtener datos usando servicios
        $students_data = $this->contributorService->getStudentsCommitData($repo_filter_int);
        $statistics = $this->contributorService->calculateCommitStatistics($students_data);

        // Configurar filtros y header usando TemplateDataService
        $filter_config = [
            'show_name' => true,
            'show_sede' => false,
            'show_curso' => false,
            'show_repo' => true,
            'show_group' => false
        ];
        
        $filters_data = $this->templateDataService->prepareFiltersConfig($filter_config);
        $header_data = $this->templateDataService->preparePageHeader(
            'fas fa-chart-line',
            'Seguimiento de Contribuciones',
            'Análisis de commits y gestión de calificaciones'
        );
        
        return array_merge($header_data, [
            // Configuración GitHub
            'github_organization' => htmlspecialchars($owner ?? 'No configurado'),
            'has_github_config' => !empty($owner),
            
            // Filtros
            'filters_config' => true,
            'filters' => $filters_data['filters'],
            'filter_script' => $filters_data['script_functions'],
            'table_id' => 'dataTable',
            
            // Datos de estudiantes
            'students' => array_values($students_data),
            'has_students' => !empty($students_data),
            
            // Estadísticas
            'statistics' => $statistics,
            
            // Datos adicionales para el template
            'cm_id' => $this->cm->id,
            'course_name' => $this->course->fullname,
            'user_fullname' => $this->user->firstname . ' ' . $this->user->lastname,
            'can_update_commits' => true,
            
            // URL para actualización
            'update_url' => $this->getPluginUrl(['tab' => 'tab3', 'update' => 1])->out(false),
        ]);
    }
    
}
