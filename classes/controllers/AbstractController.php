<?php
namespace mod_pluginpatroller\controllers;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/lib/moodlelib.php');

use mod_pluginpatroller\helpers\RoleHelper;

/**
 * Clase base abstracta para todos los controladores del plugin GitPatroller
 * Proporciona funcionalidades comunes como autenticación, permisos y renderizado
 */
abstract class AbstractController {
    
    protected $context;
    protected $course;
    protected $cm;
    protected $pluginpatroller;
    protected $user;
    
    const ROLE_TEACHER_ID = 3;
    const ROLE_STUDENT_ID = 5;
    
    public function __construct($context, $course, $cm, $pluginpatroller) {
        global $USER;
        
        $this->context = $context;
        $this->course = $course;
        $this->cm = $cm;
        $this->pluginpatroller = $pluginpatroller;
        $this->user = $USER;
    }
    
    /* Verifica si el usuario actual es estudiante - Usa RoleHelper centralizado */
    protected function isStudent(): bool {
    return RoleHelper::isStudent($this->user->id, $this->context);
    }
    
    /* Verifica si el usuario actual es profesor - Usa RoleHelper centralizado */
    protected function isTeacher(): bool {
        return RoleHelper::isTeacher($this->user->id, $this->context);
    }
    
    /* Verifica permisos de acceso para el controlador - Por defecto permite el acceso, pero puede ser sobrescrito por controladores específicos*/
    protected function checkPermissions(): bool {
        return true; 
    }
    
    /* Renderiza una plantilla Mustache con los datos proporcionados */
    protected function render(string $templateName, array $data = []): string {
        global $OUTPUT;
        $templateData = array_merge($this->getCommonTemplateData(), $data);
        return $OUTPUT->render_from_template('mod_pluginpatroller/' . $templateName, $templateData);
    }
    
    /* Genera una URL para el plugin con parámetros adicionales */
    protected function getPluginUrl(array $params = []): \moodle_url {
        $defaultParams = ['id' => $this->cm->id];
        $allParams = array_merge($defaultParams, $params);
        return new \moodle_url('/mod/pluginpatroller/view.php', $allParams);
    }
    
    /* Redirige a una URL con mensaje */
    protected function redirect($url, string $message = '', int $delay = 0): void {
        redirect($url, $message, $delay);
    }
    
    /* Obtiene parámetros de la request de forma segura */
    protected function getParam(string $name, $default = null, string $type = PARAM_TEXT) {
        return optional_param($name, $default, $type);
    }
    
    /* Obtiene parámetros requeridos de la request */
    protected function getRequiredParam(string $name, string $type = PARAM_TEXT) {
        return required_param($name, $type);
    }
    
    /*Lanza excepción si no es profesor*/
    protected function requireTeacherPermissions(): void {
        if (!$this->isTeacher()) {
            throw new \Exception('No tienes permisos para acceder a esta sección');
        }
    }
    
    /*Convierte array a formato Mustache*/
    protected function formatSelectOptions(array $options, $selected_value = null, string $placeholder = ''): array {
        $formatted = [];
        
        if (!empty($placeholder)) {
            $formatted[] = [
                'value' => '',
                'text' => $placeholder,
                'selected' => empty($selected_value)
            ];
        }
        
        // Formatear opciones principales
        foreach ($options as $value => $text) {
            $formatted[] = [
                'value' => htmlspecialchars((string)$value),
                'text' => htmlspecialchars((string)$text),
                'selected' => ($value == $selected_value)
            ];
        }
        
        return $formatted;
    }
    
    /*Datos base para todos los templates*/
    protected function getCommonTemplateData(): array {
        return [
            'cm_id' => $this->cm->id,
            'course_name' => htmlspecialchars($this->course->fullname ?? ''),
            'course_id' => $this->course->id,
            'user_id' => $this->user->id,
            'user_fullname' => htmlspecialchars($this->user->firstname . ' ' . $this->user->lastname),
            'is_student' => $this->isStudent(),
            'is_teacher' => $this->isTeacher(),
            'plugin_url' => $this->getPluginUrl()->out(false),
            'sesskey' => sesskey()
        ];
    }

    /* Método principal que debe ser implementado por cada controlador */
    abstract public function execute(): string;
    
    /* Maneja errores y excepciones del controlador */
    protected function handleError(\Exception $e): string {
        global $OUTPUT;
        error_log("Error en controlador " . get_class($this) . ": " . $e->getMessage());
        $data = [
            'error_type' => 'danger',
            'error_title' => 'Error',
            'error_message' => $e->getMessage(),
        ];
        return $OUTPUT->render_from_template('mod_pluginpatroller/error', $data);
    }
}
