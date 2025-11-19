<?php
namespace mod_pluginpatroller\service\view;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\helpers\FilterHelper;

/**
 * Servicio para preparacion de datos de templates
 * Centraliza la logica de preparacion de datos para las vistas
 */
class TemplateDataService {
    
    private $course;
    private $cm;
    
    public function __construct($course, $cm) {
        $this->course = $course;
        $this->cm = $cm;
    }
    
    //Prepara las opciones de repositorio para selectores
    public function getRepositorySelectOptions(): array {
        $repository_options = ['' => 'Seleccionar Repositorio...'];
        $repositories_data = RepositoryModel::getAllByCourseId($this->course->id);
        
        foreach ($repositories_data as $id => $name) {
            $repository_options[$id] = $name;
        }
        
        return $repository_options;
    }
    
    //Formatea opciones para selectores estándar
    public function formatSelectOptions(array $options, $selected_value = null, string $placeholder = ''): array {
        $formatted = [];
        if (!empty($placeholder)) {
            $formatted[] = [
                'value' => '',
                'text' => $placeholder
            ];
        }
        foreach ($options as $value => $text) {
            $formatted[] = [
                'value' => $value,
                'text' => $text,
                'selected' => ($value == $selected_value)
            ];
        }
        return $formatted;
    }
    
    //Formatea opciones de repositorios para modales usando IDs de base de datos
    public function formatRepositoryOptionsForModal(): array {
        global $DB;
        
        $formatted = [];
        
        // Obtener repositorios con IDs de la base de datos
        $repo_records = $DB->get_records('repositorios_data_patroller', ['id_materia' => $this->course->id]);
        
        foreach ($repo_records as $repo) {
            $formatted[] = [
                'value' => $repo->id,
                'text' => "{$repo->sede}-{$repo->curso}-{$repo->num_grupo}: {$repo->nombre_repo}"
            ];
        }
        
        return $formatted;
    }
    
    //Prepara configuración de filtros para una vista
    public function prepareFiltersConfig(array $config = []): array {
        $default_config = [
            'show_sede' => true,
            'show_curso' => true,
            'show_repo' => true,
            'show_name' => false
        ];
        
        $config = array_merge($default_config, $config);
        
        // Obtener datos crudos del helper
        $filters = FilterHelper::getFiltersData($this->course->id, $config);

        // Normalizar nombres de clave para compatibilidad con templates
        // Algunos templates esperan 'filter_script' o 'filters_script',
        // mientras que el helper principal devuelve 'script_functions'.
        $script = is_array($filters) && isset($filters['script_functions']) ? $filters['script_functions'] : '';
        $filters['filter_script'] = $script;
        $filters['filters_script'] = $script;

        return $filters;
    }
    
    //Prepara datos base para headers de página
    public function preparePageHeader(string $icon, string $title, string $subtitle): array {
        return [
            'header_icon' => $icon,
            'header_title' => $title,
            'header_subtitle' => $subtitle,
            'cm_id' => $this->cm->id,
            'course_name' => $this->course->fullname
        ];
    }
    
    //Prepara mensaje de éxito para mostrar en template
    public function prepareSuccessMessage(?string $message): array {
        return [
            'success_message' => $message,
            'has_success' => !empty($message)
        ];
    }

    //Prepara datos matriciales de alumnos pendientes por sede y curso
    public function preparePendingGroupsMatrix(array $users_by_group, array $users_by_repo, int $max_students_per_repo,$groupService): array {
        // 1. Obtener sedes y letras únicas
        $sedes = $this->extractUniqueSedes($users_by_group);
        $course_letters = $this->extractUniqueCourseLetters($users_by_group);

        // 2. Construir matriz de datos
        $sedes_data = [];
        foreach ($sedes as $sede) {
            $courses_data = [];
            foreach ($course_letters as $letter) {
                $group_key = $sede . '-' . $letter;
                $course_data = $this->buildCourseData(
                    $group_key,
                    $users_by_group,
                    $users_by_repo,
                    $max_students_per_repo,
                    $groupService
                );
                $courses_data[] = $course_data;
            }
            
            $sedes_data[] = [
                'sede_name' => $sede,
                'courses' => $courses_data
            ];
        }
        
        return $sedes_data;
    }

    //Extrae sedes únicas de los grupos
    private function extractUniqueSedes(array $users_by_group): array {
        $sedes = [];
        foreach ($users_by_group as $group_key => $students_in_course) {
            $parts = explode('-', $group_key);
            if (count($parts) >= 2) {
                $sedes[] = $parts[0];
            }
        }
        $sedes = array_unique($sedes);
        sort($sedes);
        return $sedes;
    }

    //Extrae letras de curso únicas de los grupos
    private function extractUniqueCourseLetters(array $users_by_group): array {
        $course_letters = [];
        foreach ($users_by_group as $group_key => $students_in_course) {
            $parts = explode('-', $group_key);
            if (count($parts) >= 2) {
                $course_letters[] = $parts[1];
            }
        }
        $course_letters = array_unique($course_letters);
        sort($course_letters);
        return $course_letters;
    }

    //Construye datos de un curso específico con opciones de repositorios
    private function buildCourseData(string $group_key, array $users_by_group, array $users_by_repo,int $max_students_per_repo,$groupService): array {
        $pending_count = $groupService->getPendingStudentsCountForGroup($users_by_group, $users_by_repo, $group_key);
        
        $course_data = [
            'group_key' => $group_key,
            'has_pending' => $pending_count > 0,
            'pending_count' => $pending_count
        ];
        
        if ($pending_count > 0) {
            // Calcular cuántos repositorios realmente se necesitan
            $existing_repos_for_group = $groupService->countReposForGroup($users_by_repo, $group_key);
            $total_students_in_group = count($users_by_group[$group_key]['students']);
            $total_repos_needed = (int)ceil($total_students_in_group / $max_students_per_repo);
            
            $repos_to_create = max(0, $total_repos_needed - $existing_repos_for_group);
            
            // Generar opciones solo si se necesitan más repositorios
            if ($repos_to_create > 0) {
                $course_data['repo_options'] = $this->generateRepoOptions($repos_to_create);
            } else {
                $course_data['has_pending'] = false;
                $course_data['pending_count'] = 0;
            }
        }
        
        return $course_data;
    }

    //Genera opciones de repositorios para el dropdown
    private function generateRepoOptions(int $repos_to_create): array {
        $repo_options = [];
        for ($i = 1; $i <= $repos_to_create; $i++) {
            if ($i === 1) {
                $text = get_string('repo_to_add_singular', 'mod_pluginpatroller');
            } else {
                $text = get_string('repo_to_add_plural', 'mod_pluginpatroller', $i);
            }

            $repo_options[] = [
                'value' => $i,
                'text' => $text
            ];
        }
        return $repo_options;
    }
}