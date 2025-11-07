<?php
namespace mod_pluginpatroller\service;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\UserModel;
use mod_pluginpatroller\service\GitHubAccessService;

/**
 * Servicio especializado para gestion de estudiantes
 * Maneja operaciones de rehabilitacion y cambio de repositorio
 */
class StudentManagementService {
    
    private $course;
    
    public function __construct($course) {
        $this->course = $course;
    }
    
    /**
     * Cambia el repositorio de un estudiante directamente
     * 
     * @param int $student_id ID del estudiante
     * @param int $new_repository_id ID del nuevo repositorio
     * @return array ['success' => bool, 'message' => string]
     */
    public function changeStudentRepository(int $student_id, int $new_repository_id): array {
        global $DB;
        
        try {
            // Verificar que el estudiante existe
            $student = $DB->get_record('usuarios_data_patroller', [
                'user_id' => $student_id,
                'id_materia' => $this->course->id
            ]);
            
            if (!$student) {
                return ['success' => false, 'message' => 'Estudiante no encontrado en este curso.'];
            }
            
            // Verificar que el repositorio existe y pertenece al curso
            $repository = $DB->get_record('repositorios_data_patroller', [
                'id' => $new_repository_id,
                'id_materia' => $this->course->id
            ]);
            
            if (!$repository) {
                return ['success' => false, 'message' => 'Repositorio no encontrado en este curso.'];
            }
            
            // Actualizar el repositorio del estudiante y resetear estado de invitación
            $update_data = new \stdClass();
            $update_data->id = $student->id;
            $update_data->id_repo = $new_repository_id;
            $update_data->invitation_status = 'not_invited';
            $update_data->date_invited = null;
            $update_data->date_accepted = null;
            
            $result = $DB->update_record('usuarios_data_patroller', $update_data);
            
            if ($result) {
                $user = $DB->get_record('user', ['id' => $student_id]);
                return [
                    'success' => true, 
                    'message' => "Repositorio de {$user->firstname} {$user->lastname} cambiado a '{$repository->nombre_repo}' correctamente."
                ];
            } else {
                return ['success' => false, 'message' => 'Error al actualizar la base de datos.'];
            }
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }
    
    /**
     * Obtiene información de un estudiante para mostrar en formularios
     * 
     * @param int $student_id ID del estudiante
     * @return array|null ['id' => int, 'name' => string, 'email' => string] o null si no existe
     */
    public function getStudentInfo(int $student_id): ?array {
        global $DB;
        
        $user = $DB->get_record('user', ['id' => $student_id]);
        if (!$user) {
            return null;
        }
        
        // Verificar que el estudiante pertenece al curso
        $student = $DB->get_record('usuarios_data_patroller', [
            'user_id' => $student_id,
            'id_materia' => $this->course->id
        ]);
        
        if (!$student) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'name' => $user->firstname . ' ' . $user->lastname,
            'email' => $user->email
        ];
    }
}