<?php
namespace mod_pluginpatroller\service\repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Servicio para gestión de asignaciones de repositorios:
 * - Procesar cambios de asignación de repositorios a estudiantes
 * - Actualizar estados de invitación al cambiar repositorios
 * - Orquestar guardado de cambios desde formularios
 */
class RepositoryAssignmentService {
    
    private $course;

    public function __construct($course) {
        $this->course = $course;
    }
    
    //Procesa cambios guardados desde el formulario (solo repositorios)
    public function processSaveChanges(array $github_changes, array $repo_changes): void {
    
        if (!empty($repo_changes)) {
            $this->processRepositoryChanges($repo_changes);
        }
    }
    
    //Procesa cambios de asignación de repositorios
    private function processRepositoryChanges(array $repo_changes): void {
        global $DB;

        foreach ($repo_changes as $student_id => $new_repo_id) {
            if (empty($new_repo_id)) {
                continue;
            }
            $repo_id_clean = (int)$new_repo_id;
            $student = $DB->get_record('usuarios_data_patroller', ['id' => $student_id]);
            if (!$student) {
                continue; // no existe en BD
            }
            // Verificar si el registro está en estado de solo lectura
            if (in_array($student->invitacion_status, [3, 4, 5])) {
                continue; // aceptado o pendiente → no modificar
            }
            // Preparar objeto para actualización
            $update = (object)[
                'id' => $student_id,
                'id_repo' => $repo_id_clean,
                'invitacion_status' => 0 // reset a "no enviada" siempre que cambia de repo
            ];
            // Guardar cambios
            $DB->update_record('usuarios_data_patroller', $update);
        }
    }

    //Obtiene lista de repositorios disponibles con espacio suficiente
    public function getRepositoriesWithCapacity(array $raw_repos, int $current_user_id, int $course_id, int $max_capacity, ?string $current_repo_name = null): array {
        global $DB;
        
        $available_options = [];
        
        foreach ($raw_repos as $repo_id => $repo_name) {
            // Excluir el repositorio actual del estudiante
            if ($current_repo_name && $repo_name === $current_repo_name) {
                continue;
            }
            
            // Contar estudiantes actuales en este repo (excluyendo al usuario que se está cambiando)
            $current_count = $DB->count_records_sql(
                "SELECT COUNT(*) 
                FROM {usuarios_data_patroller} 
                WHERE id_repo = ? 
                AND id_materia = ? 
                AND id_usuario != ?",
                [$repo_id, $course_id, $current_user_id]
            );
            
            // Solo agregar si hay espacio disponible
            if ($current_count < $max_capacity) {
                $spaces_left = $max_capacity - $current_count;
                $available_options[] = [
                    'value' => $repo_name,
                    'text' => "{$repo_name} ({$spaces_left} lugar" . ($spaces_left != 1 ? 'es' : '') . " disponible" . ($spaces_left != 1 ? 's' : '') . ")",
                    'selected' => false
                ];
            }
        }
        
        // Si no hay repos disponibles, agregar mensaje informativo
        if (empty($available_options)) {
            $available_options[] = [
                'value' => '',
                'text' => '⚠️ No hay otros repositorios con espacio disponible',
                'selected' => false
            ];
        }
        
        return $available_options;
    }

    //Cambia el repositorio asignado a un estudiante
    public function changeStudentRepository(int $user_id, string $new_repo_name, int $course_id, int $max_capacity): void {
        global $DB;

        if (empty($new_repo_name)) {
            throw new \Exception('Debe seleccionar un repositorio');
        }
        
        // Validar que el repositorio existe y pertenece al curso
        $repo = $DB->get_record('repositorios_data_patroller', [
            'nombre_repo' => $new_repo_name,
            'id_materia' => $course_id
        ]);

        if (!$repo) {
            throw new \Exception('Repositorio inválido o no pertenece a este curso');
        }

        // Verificar que el estudiante existe en la tabla
        $student = $DB->get_record('usuarios_data_patroller', [
            'id_usuario' => $user_id,
            'id_materia' => $course_id
        ]);

        if (!$student) {
            throw new \Exception('Estudiante no encontrado');
        }

        // Verificar capacidad del repositorio (excluyendo al usuario actual)
        $current_count = $DB->count_records_sql(
            "SELECT COUNT(*) 
            FROM {usuarios_data_patroller} 
            WHERE id_repo = ? 
            AND id_materia = ? 
            AND id_usuario != ?",
            [$repo->id, $course_id, $user_id]
        );

        if ($current_count >= $max_capacity) {
            throw new \Exception("El repositorio '{$new_repo_name}' ya alcanzó su capacidad máxima ({$max_capacity} estudiantes)");
        }

        // Actualizar el repositorio y reiniciar estado de invitación
        $DB->execute(
            "UPDATE {usuarios_data_patroller} 
            SET id_repo = ?, 
                invitacion_status = 0
            WHERE id_usuario = ? 
            AND id_materia = ?",
            [$repo->id, $user_id, $course_id]
        );
    }
}
