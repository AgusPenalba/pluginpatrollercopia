<?php
namespace mod_pluginpatroller\controllers;

use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\helpers\FilterHelper;

require_once(__DIR__ . '/../service/CommitService.php');
require_once(__DIR__ . '/../service/GradebookService.php');
use mod_pluginpatroller\service\CommitService;
use mod_pluginpatroller\service\GradebookService;
use mod_pluginpatroller\service\ContributorInsightsService;
use mod_pluginpatroller\model\RepositoryModel;

/**
 * Controller optimizado para seguimiento de commits y calificaciones 
 * Usa servicios especializados para separar lógica de negocio
 */
class ContributorsController extends AbstractController {
    
    private ContributorInsightsService $contributorService;
    private GradebookService $gradebookService;
    
    public function __construct($context, $course, $cm, $pluginpatroller) {
        parent::__construct($context, $course, $cm, $pluginpatroller);
        $this->initializeServices();
    }
    
    /**
     * Procesa actualización de commits y guardado de calificaciones
     */
    public function execute(): string {
        $this->initializeServices();
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
    
    /**
     * Inicializa servicios especializados
     */
    private function initializeServices(): void {
        $this->contributorService = new ContributorInsightsService($this->course, $this->pluginpatroller);
        $this->gradebookService = new GradebookService();
    }
    
    /**
     * Actualiza los datos de commits usando CommitService
     */
    private function updateCommits(): void {
        $this->contributorService->updateCommits();
    }
    
    /**
     * Procesa el guardado de calificaciones usando servicios
     */
    private function processSaveGrades(): void {
        $calificaciones = optional_param_array('calificacion', [], PARAM_RAW);
        $this->contributorService->processSaveGrades($calificaciones);
    }
    
    /**
     * Prepara todos los datos para el template usando servicios especializados
     */
    private function prepareTemplateData(): array {
        $owner = ConfigHelper::getGitHubOwner();
        $repo_filter = $this->getParam('filterRepo', 'All');
        
        // Convertir filtro a int si no es 'All'
        $repo_filter_int = ($repo_filter === 'All') ? null : (int)$repo_filter;
        
        // Obtener datos usando servicios
        $students_data = $this->contributorService->getStudentsCommitData($repo_filter_int);
        $statistics = $this->contributorService->calculateCommitStatistics($students_data);
        $repository_options = $this->contributorService->getRepositoryFilterOptions();
        
        // Extraer repositorios únicos de los datos de estudiantes para el filtro
        $active_repositories = [];
        $has_unassigned = false;
        
        foreach ($students_data as $student) {
            $repo_name = $student['repo_name'] ?? '';
            
            if (empty($repo_name) || $repo_name === 'Sin asignar') {
                // Marcar que hay estudiantes sin asignar
                $has_unassigned = true;
            } else {
                // Agregar repositorio específico (evitar duplicados)
                if (!isset($active_repositories[$repo_name])) {
                    $active_repositories[$repo_name] = $repo_name;
                }
            }
        }
        
        // Si hay estudiantes sin asignar, agregar esa opción
        if ($has_unassigned) {
            $active_repositories['Sin asignar'] = 'Sin asignar';
        }
        
        //  Configurar filtros usando FilterHelper con repositorios activos
        $filters_config = FilterHelper::getFiltersData($this->course->id, [
            'show_name' => true,      // ← Filtro de nombre (nuevo)
            'show_sede' => false,
            'show_curso' => false,
            'show_repo' => true,      // ← Filtro de repositorio (mantener el anterior)
            'show_group' => false
        ], $active_repositories); // ← Pasar repositorios activos extraídos
        
        return [
            // Header del template
            'header_icon' => 'fas fa-chart-line',
            'header_title' => 'Seguimiento de Contribuciones',
            'header_subtitle' => 'Análisis de commits y gestión de calificaciones',
            
            // Configuración GitHub
            'github_organization' => htmlspecialchars($owner ?? 'No configurado'),
            'has_github_config' => !empty($owner),
            
            // Filtros usando FilterHelper (NUEVO SISTEMA)
            'filters_config' => $filters_config,
            'filter_script' => FilterHelper::getFilterScriptFunctions(),
            
            // Filtros antiguos (mantener por compatibilidad temporal)
            'repository_options' => $this->formatRepositoryOptions($repository_options, $repo_filter),
            'current_filter' => $repo_filter,
            
            // Datos de estudiantes
            'students' => array_values($students_data),
            'has_students' => !empty($students_data),
            
            // Estadísticas
            'statistics' => $statistics,
            
            // Datos adicionales para el template
            'cm_id' => $this->cm->id,
            'course_name' => $this->course->fullname,
            'user_fullname' => $GLOBALS['USER']->firstname . ' ' . $GLOBALS['USER']->lastname,
            'can_update_commits' => true, // Permitir actualización de commits
            
            // URL para actualización
            'update_url' => $this->getPluginUrl(['tab' => 'tab3', 'update' => 1])->out(false),
        ];
    }
    
    /**
     * Formatea opciones de repositorio para el template
     */
    private function formatRepositoryOptions(array $repositories, string $selected): array {
        $options = [
            [
                'value' => 'All',
                'name' => 'Todos los repositorios',
                'selected' => ($selected === 'All')
            ]
        ];
        
        foreach ($repositories as $repo_id => $repo_name) {
            $options[] = [
                'value' => $repo_id,
                'name' => $repo_name,
                'selected' => ($selected == $repo_id)
            ];
        }
        
        return $options;
    }
    
    /**
     * Genera HTML básico para filtro de repositorios con datos reales
     */
    private function generateBasicRepositoryFilter(array $repository_options, string $selected): string {
        // Preparar opciones para el template
        $options = [];
        
        foreach ($repository_options as $repo_id => $repo_name) {
            $is_selected = false;
            if ($selected === 'All' && $repo_id == 0) {
                $is_selected = true;
            } elseif ($selected == $repo_id) {
                $is_selected = true;
            }
            
            $value = ($repo_id == 0) ? 'All' : $repo_id;
            $options[] = [
                'value' => $value,
                'text' => $repo_name,
                'selected' => $is_selected
            ];
        }
        
        $data = [
            'cm_id' => $this->cm->id,
            'repository_options' => $options
        ];
        
        global $OUTPUT;
        return $OUTPUT->render_from_template('mod_pluginpatroller/contributors_filters', $data);
    }
    
    /**
     * Genera mensaje cuando no hay repositorios para filtrar
     */
    private function generateEmptyFilterMessage(): string {
        $data = [
            'message_type' => 'info',
            'message_icon' => 'info-circle',
            'message_title' => 'Sin filtros disponibles:',
            'message_text' => 'No hay repositorios creados para filtrar.'
        ];
        
        global $OUTPUT;
        return $OUTPUT->render_from_template('mod_pluginpatroller/alert_messages', $data);
    }
    

}
