<?php
namespace mod_pluginpatroller\controllers;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\service\StudentViewService;
use mod_pluginpatroller\helpers\FilterHelper;

/* Vista dual: para estudiantes (compañeros de curso) y profesores (usuarios sin registrar) */
class StudentViewController extends AbstractController {
    
    private StudentViewService $studentViewService;
    
    public function __construct($context, $course, $cm, $pluginpatroller) {
        parent::__construct($context, $course, $cm, $pluginpatroller);
        $this->studentViewService = new StudentViewService($this->course, $this->context, $this->user->id);
    }
    
    /*Determina qué vista mostrar según el rol del usuario
     * - Estudiantes: ven compañeros de su sede/curso y pueden elegir repositorio
     * - Profesores: ven lista de usuarios no registrados en el sistema*/
    public function execute(): string {
        // Inicializar servicios al inicio
        $this->studentViewService = new StudentViewService($this->course, $this->context, $this->user->id);
        
        try {
            // request processing for student view
            $guardar_repositorio = $this->getParam('guardar_repositorio');
            
            if ($guardar_repositorio) {
                // processing student repository selection
                $this->processStudentRepositorySelection();
                $this->redirect($this->getPluginUrl(), 'Datos actualizados correctamente.', 2);
            }
            
            // Renderizar vista según el rol
            if ($this->isStudent()) {
                $data = $this->prepareStudentViewData();
                return $this->render('vista_alumno', $data);
            } else {
                $data = $this->prepareUnregisteredStudentsData();
                return $this->render('usuarios_sin_registrar', $data);
            }
            
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }
    
    /*Organiza datos para que estudiante vea sus compañeros usando servicio especializado*/
    private function prepareStudentViewData(): array {
        $service_data = $this->studentViewService->getStudentViewData($this->user);
        
        // debug traces removed
        
        // Configurar filtros para vista de estudiante
        $filter_config = [
            'show_name' => true,
            'show_sede' => false,  // Ya visible en tabla
            'show_curso' => false, // Ya visible en tabla  
            'show_repo' => false
        ];
        $filters_data = FilterHelper::getFiltersData($this->course->id, $filter_config);
        
        return array_merge($service_data, [
            // Header para vista estudiante
            'header_icon' => 'fas fa-users',
            'header_title' => 'Compañeros de Curso',
            'header_subtitle' => 'Ver y gestionar información de tu grupo académico',
            // Variables necesarias para el template
            'sesskey' => sesskey(),
            'cm_id' => $this->cm->id,
            // Datos de filtros
            'filters' => $filters_data['filters'],
            'filters_script' => FilterHelper::getFilterScriptFunctions(),
            'table_id' => 'classmatesTable'
        ]);
    }
    
    /*Lista usuarios no registrados en el sistema usando servicio*/
    private function prepareUnregisteredStudentsData(): array {
        $filter_config = [
            'show_name' => true,
            'show_sede' => true,
            'show_curso' => true,
            'show_repo' => false
        ];
        $filters_data = FilterHelper::getFiltersData($this->course->id, $filter_config);
        
        // Obtener usuarios sin registrar usando servicio
        $unregistered_students = $this->studentViewService->getUnregisteredStudentsData();
        
        return [
            // Header para vista profesores
            'header_icon' => 'fas fa-user-plus',
            'header_title' => 'Estudiantes Sin Registrar',
            'header_subtitle' => 'Usuarios matriculados pendientes de registro en GitPatroller',
            
            // Datos de filtros directamente en el nivel superior
            'filters' => $filters_data['filters'],
            'filters_script' => FilterHelper::getFilterScriptFunctions(),
            'table_id' => 'unregisteredTable',
            'unregistered_students' => $unregistered_students,
            'has_unregistered' => !empty($unregistered_students),
            'total_unregistered' => count($unregistered_students)
        ];
    }
    
    /*Guarda repositorio elegido por estudiante usando servicio*/
    private function processStudentRepositorySelection(): void {
        $alumno_id = (int)$this->getParam('alumno_id');
        $repositorio_id = (int)$this->getParam('repositorio', 0);
        $github_username = trim($this->getParam('github_username', ''));
        
        if ($alumno_id <= 0) {
            throw new \Exception('ID de alumno no válido');
        }

        $this->studentViewService->processRepositorySelection($alumno_id, $repositorio_id, $github_username);
    }
}
