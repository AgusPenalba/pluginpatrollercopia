<?php
namespace mod_pluginpatroller\controllers;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\service\view\StudentViewService;
use mod_pluginpatroller\service\view\TemplateDataService;
use mod_pluginpatroller\helpers\FilterHelper;

class StudentViewController extends AbstractController {
    
    private StudentViewService $studentViewService;
    private TemplateDataService $templateDataService;
    
    public function __construct($context, $course, $cm, $pluginpatroller) {
        parent::__construct($context, $course, $cm, $pluginpatroller);

        $this->studentViewService = new StudentViewService($this->course, $this->context, $this->user->id);
        $this->templateDataService = new TemplateDataService($this->course, $this->cm);
    }
    
    /*Determina qué vista mostrar según el rol del usuario
     * - Estudiantes: ven compañeros de su sede/curso y pueden elegir repositorio
     * - Profesores: ven lista de usuarios no registrados en el sistema*/
    public function execute(): string {
        try {
            $guardar_repositorio = $this->getParam('guardar_repositorio');
            
            if ($guardar_repositorio) {
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

    //Organiza datos para que estudiante vea sus compañeros usando servicio especializado
    private function prepareStudentViewData(): array {
        $service_data = $this->studentViewService->getStudentViewData($this->user);
        
        $filter_config = [
            'show_name' => true,
            'show_sede' => false,
            'show_curso' => false,
            'show_repo' => false
        ];
        
        $filters_data = $this->templateDataService->prepareFiltersConfig($filter_config);
        $header_data = $this->templateDataService->preparePageHeader(
            'fas fa-users',
            'Compañeros de Curso',
            'Ver y gestionar información de tu grupo académico'
        );
        
        return array_merge($service_data, $header_data, [
            'sesskey' => sesskey(),
            'cm_id' => $this->cm->id,
            'filters' => $filters_data['filters'],
            'filters_script' => $filters_data['script_functions'],
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
        
        $filters_data = $this->templateDataService->prepareFiltersConfig($filter_config);
        $header_data = $this->templateDataService->preparePageHeader(
            'fas fa-user-plus',
            'Estudiantes Sin Registrar',
            'Usuarios matriculados pendientes de registro en GitPatroller'
        );
        
        $unregistered_students = $this->studentViewService->getUnregisteredStudentsData();
        
        return array_merge($header_data, [
            'filters' => $filters_data['filters'],
            'filters_script' => $filters_data['script_functions'],
            'table_id' => 'unregisteredTable',
            'unregistered_students' => $unregistered_students,
            'has_unregistered' => !empty($unregistered_students),
            'total_unregistered' => count($unregistered_students)
        ]);
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
