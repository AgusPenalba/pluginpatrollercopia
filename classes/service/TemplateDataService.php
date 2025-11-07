<?php
namespace mod_pluginpatroller\service;

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
    
    /**
     * Prepara las opciones de repositorio para selectores
     * 
     * @return array Opciones formateadas para templates
     */
    public function getRepositorySelectOptions(): array {
        $repository_options = ['' => 'Seleccionar Repositorio...'];
        $repositories_data = RepositoryModel::getAllByCourseId($this->course->id);
        
        foreach ($repositories_data as $id => $name) {
            $repository_options[$id] = $name;
        }
        
        return $repository_options;
    }
    
    /**
     * Formatea opciones para selectores estándar
     * 
     * @param array $options Array asociativo [value => text]
     * @param string|null $selected_value Valor seleccionado
     * @param string $placeholder Texto placeholder
     * @return array Opciones formateadas para Mustache
     */
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
    
    /**
     * Formatea opciones de repositorios para modales usando IDs de base de datos
     * 
     * @return array Opciones formateadas
     */
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
    
    /**
     * Prepara configuración de filtros para una vista
     * 
     * @param array $config Configuración de filtros
     * @return array Datos de filtros para template
     */
    public function prepareFiltersConfig(array $config = []): array {
        $default_config = [
            'show_sede' => true,
            'show_curso' => true,
            'show_repo' => true,
            'show_name' => false
        ];
        
        $config = array_merge($default_config, $config);
        
        return FilterHelper::getFiltersData($this->course->id, $config);
    }
    
    /**
     * Prepara datos base para headers de página
     * 
     * @param string $icon Icono de la página
     * @param string $title Título de la página
     * @param string $subtitle Subtítulo de la página
     * @return array Datos del header
     */
    public function preparePageHeader(string $icon, string $title, string $subtitle): array {
        return [
            'header_icon' => $icon,
            'header_title' => $title,
            'header_subtitle' => $subtitle,
            'cm_id' => $this->cm->id,
            'course_name' => $this->course->fullname
        ];
    }
    
    /**
     * Prepara mensaje de éxito para mostrar en template
     * 
     * @param string|null $message Mensaje de éxito
     * @return array Datos del mensaje
     */
    public function prepareSuccessMessage(?string $message): array {
        return [
            'success_message' => $message,
            'has_success' => !empty($message)
        ];
    }
}