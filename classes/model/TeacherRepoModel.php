<?php
// filepath: c:\Users\usuario\Desktop\MoodleWindowsInstaller-latest\server\moodle\mod\pluginpatroller\classes\model\TeacherRepoModel.php

namespace mod_pluginpatroller\model;

defined('MOODLE_INTERNAL') || die();

/**
 * Modelo para la gestión de repositorios asignados a profesores
 * 
 * Maneja toda la interacción con la base de datos para las asignaciones
 * profesor-repositorio, incluyendo el estado de las invitaciones de GitHub.
 * 
 * @package    mod_pluginpatroller
 * @copyright  2025 Tu Institución
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class TeacherRepoModel {

    /**
     * Obtiene todos los repositorios de un curso específico
     * 
     * @param int $courseid ID del curso en Moodle
     * @return array Lista de objetos repositorio, vacío si no hay resultados
     */
    public static function getAllByCourseId(int $courseid): array {
        global $DB;
        
        try {
            if (!$DB->get_manager()->table_exists('repositorios_data_patroller')) {
                return [];
            }
            
            $repos = $DB->get_records('repositorios_data_patroller', ['id_materia' => $courseid]);
            return $repos ? array_values($repos) : [];
            
        } catch (\Exception $e) {
            debugging('Error en getAllByCourseId: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
    }
    
    /**
     * Obtiene todas las asignaciones profesor-repositorio de un curso
     * 
     * Realiza un JOIN entre repos_profesores y repositorios_data_patroller
     * para obtener la información completa de cada asignación.
     * 
     * @param int $courseid ID del curso
     * @return array Lista de asignaciones con información del repositorio
     */
    public static function getTeacherRepoAssignments(int $courseid): array {
        global $DB;
        
        try {
            if (!$DB->get_manager()->table_exists('repos_profesores') || 
                !$DB->get_manager()->table_exists('repositorios_data_patroller')) {
                return [];
            }
            
            $sql = "SELECT rp.*, rdp.nombre_repo, rdp.sede, rdp.curso, rdp.num_grupo
                    FROM {repos_profesores} rp
                    JOIN {repositorios_data_patroller} rdp ON rdp.id = rp.repo_id
                    WHERE rdp.id_materia = :courseid";
            
            $assignments = $DB->get_records_sql($sql, ['courseid' => $courseid]);
            return $assignments ? array_values($assignments) : [];
            
        } catch (\Exception $e) {
            debugging('Error en getTeacherRepoAssignments: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
    }
    
    /**
     * Obtiene el nombre de usuario de GitHub del profesor desde la BD
     * 
     * Busca el username en cualquier asignación existente del profesor.
     * Retorna el primer username encontrado (deberían ser todos iguales).
     * 
     * @param int $userid ID del usuario/profesor en Moodle
     * @return string|null Username de GitHub o null si no existe
     */
    public static function getTeacherGithubUsername(int $userid): ?string {
        global $DB;
        
        try {
            if (!$DB->get_manager()->table_exists('repos_profesores')) {
                return null;
            }
            
            $sql = "SELECT github_username 
                    FROM {repos_profesores} 
                    WHERE userid = :userid 
                    AND github_username IS NOT NULL 
                    AND github_username != ''
                    LIMIT 1";
            
            $record = $DB->get_record_sql($sql, ['userid' => $userid]);
            return $record && !empty($record->github_username) ? $record->github_username : null;
            
        } catch (\Exception $e) {
            debugging('Error en getTeacherGithubUsername: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }
    
    /**
     * Obtiene una asignación específica por repo_id y userid
     * 
     * @param int $repoid ID del repositorio
     * @param int $userid ID del profesor
     * @return object|false Objeto de asignación o false si no existe
     */
    public static function getAssignment(int $repoid, int $userid) {
        global $DB;
        
        try {
            return $DB->get_record('repos_profesores', [
                'repo_id' => $repoid,
                'userid' => $userid
            ]);
        } catch (\Exception $e) {
            debugging('Error en getAssignment: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
    
    /**
     * Crea una nueva asignación profesor-repositorio
     * 
     * Si la asignación ya existe, retorna su ID sin crear duplicado.
     * 
     * @param int $repoid ID del repositorio
     * @param int $userid ID del profesor
     * @param string $github_username Username de GitHub del profesor
     * @return int|false ID de la asignación creada/existente o false en error
     */
    public static function createAssignment(int $repoid, int $userid, string $github_username) {
        global $DB;
        
        try {
            // Verificar duplicados
            $existing = self::getAssignment($repoid, $userid);
            if ($existing) {
                return $existing->id;
            }
            
            // Crear nuevo registro
            $record = new \stdClass();
            $record->repo_id = $repoid;
            $record->userid = $userid;
            $record->github_username = $github_username;
            $record->invitacion_status = \mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus::UNPROCESSED;
            $record->timecreated = time();
            $record->timemodified = time();
            
            return $DB->insert_record('repos_profesores', $record);
            
        } catch (\Exception $e) {
            debugging('Error en createAssignment: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
    
    /**
     * Actualiza el estado de invitación de una asignación
     * 
     * @param int $repoid ID del repositorio
     * @param int $userid ID del profesor
     * @param int $status Nuevo estado (constante de GitHubRegistrationStatus)
     * @return bool True si se actualizó correctamente
     */
    public static function updateInvitationStatus(int $repoid, int $userid, int $status): bool {
        global $DB;
        
        try {
            $assignment = $DB->get_record('repos_profesores', [
                'repo_id' => $repoid,
                'userid' => $userid
            ]);
            
            if (!$assignment) {
                return false;
            }
            
            $assignment->invitacion_status = $status;
            $assignment->timemodified = time();
            
            return $DB->update_record('repos_profesores', $assignment);
            
        } catch (\Exception $e) {
            debugging('Error en updateInvitationStatus: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
    
    /**
     * Elimina una asignación específica profesor-repositorio
     * 
     * @param int $repoid ID del repositorio
     * @param int $userid ID del profesor
     * @return bool True si se eliminó correctamente o no existía
     */
    public static function deleteAssignment(int $repoid, int $userid): bool {
        global $DB;
        try {
            return $DB->delete_records('repos_profesores', [
                'repo_id' => $repoid,
                'userid' => $userid
            ]);
        } catch (\Exception $e) {
            debugging('Error en deleteAssignment: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}