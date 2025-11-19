<?php
namespace mod_pluginpatroller\service\repository;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\helpers\UserHelper;

/**
 * Servicio para creación de repositorios en GitHub:
 * - Crear repositorios masivamente en GitHub según grupos de estudiantes
 * - Generar nombres de repositorios siguiendo convención del curso
 * - Guardar registros de repositorios en la base de datos
 * - Obtener estructura de repositorios con estudiantes asignados
 */
class RepositoryCreationService {
    
    private $course;
    private $githubAPI;
    
    public function __construct($course, GitHubPatrollerAPI $githubAPI) {
        $this->course = $course;
        $this->githubAPI = $githubAPI;
    }
    
    //Procesa la creación masiva de repositorios según la selección del formulario
    public function processCreateRepos(array $selected_groups, array $repos_to_add, array $users_by_repo): void {
        if (empty($selected_groups)) {
            return;
        }
        
        // Extraer metadata del curso (año, cuatrimestre, prefijo) para nombres de repos
        $course_metadata = $this->parseCourseMetadata();
        
        foreach ($selected_groups as $group_key) {
            $to_create = (int)($repos_to_add[$group_key] ?? 0);
            
            if ($to_create <= 0) {
                continue;
            }
            
            $this->createRepositoriesForGroup($group_key, $to_create, $users_by_repo, $course_metadata);
        }
    }
    
    //Obtiene el primer número de repositorio disponible para un grupo específico
    public function getLastRepoNumForGroup(array $users_by_repo, string $sede, string $curso): int {
        // Recolectar todos los números existentes para este grupo
        $existing_numbers = [];
        
        foreach ($users_by_repo as $repo) {
            // Solo considerar repos del mismo grupo (sede + curso)
            if ($repo['sede'] === $sede && $repo['curso'] === $curso) {
                $existing_numbers[] = (int)$repo['nro'];
            }
        }
        
        // Si no hay repos, empezar desde 0
        if (empty($existing_numbers)) {
            return 0;
        }
        
        // Ordenar los números
        sort($existing_numbers);
        
        // Buscar el primer hueco disponible
        $expected = 1;
        foreach ($existing_numbers as $num) {
            if ($num > $expected) {
                // Encontramos un hueco, retornar el número anterior
                return $expected - 1;
            }
            $expected = $num + 1;
        }
        
        // No hay huecos, retornar el máximo
        return max($existing_numbers);
    }
    
    //Obtiene repositorios con sus estudiantes anidados de forma optimizada
    public function getReposWithNestedStudents(): array {
        global $DB;
        $repos_con_alumnos = [];
        
        $repositorios = $DB->get_records('repositorios_data_patroller', ['id_materia' => $this->course->id]);
        
        foreach ($repositorios as $r) {
            // Obtener estudiantes asignados a este repositorio específico
            $students = \mod_pluginpatroller\model\UserModel::get_students_by_repo($r->id, $this->course->id);
            
            // Construir estructura con datos del repo y sus estudiantes
            $repos_con_alumnos[$r->id] = [
                'id' => $r->id,                      // ID del repositorio en BD
                'nombre_repo' => $r->nombre_repo,    // Nombre completo del repo (ej: prf-2025c1-Belgrano-YA-1)
                'curso' => $r->curso,                // Curso (ej: 'YA', 'YB')
                'sede' => $r->sede,                  // Sede (ej: 'Belgrano', 'Centro')
                'nro' => $r->num_grupo,              // Número del grupo (ej: 1, 2, 3)
                'students' => $students,             // Array de estudiantes asignados
            ];
        }
        
        return $repos_con_alumnos;
    }
    
    //Extrae metadata del curso desde su shortname
    private function parseCourseMetadata(): array {
        // Dividir el shortname del curso por guiones (ej: PRF-2025-C1-YA)
        $course_parts = explode('-', $this->course->shortname);
        
        return [
            'cuatrimestre' => $course_parts[2] ?? '',  // Ej: 'C1', 'C2'
            'anio' => $course_parts[1] ?? '',          // Ej: '2025'
            'prefix' => $course_parts[0] ?? 'prf'      // Ej: 'PRF' (default 'prf' minúscula)
        ];
    }
    
    //Crea múltiples repositorios para un grupo específico (sede + curso)
    private function createRepositoriesForGroup(string $group_key, int $to_create, array $users_by_repo, array $metadata): void {
        [$sede, $curso] = UserHelper::splitGroupKey($group_key); // Separar la clave del grupo en sede y curso (ej: 'Belgrano-YA' → ['Belgrano', 'YA'])

        
        $last_repo_num = $this->getLastRepoNumForGroup($users_by_repo, $sede, $curso);
        
        for ($i = 1; $i <= $to_create; $i++) {
            $new_repo_num = $last_repo_num + $i;
            
            $repo_name = $this->generateRepoName($metadata, $sede, $curso, $new_repo_num);
            
            try {
                if ($this->githubAPI->createRepository($repo_name)) {
                    $this->insertRepositoryRecord($repo_name, $sede, $curso, $new_repo_num);
                }
            } catch (\Exception $e) {
                error_log("Error creando repositorio {$repo_name}: " . $e->getMessage());
            }
        }
    }
    
    //Genera el nombre del repositorio siguiendo la convención del curso
    private function generateRepoName(array $metadata, string $sede, string $curso, int $num): string {
        // Concatenar todas las partes con guiones
        return "{$metadata['prefix']}-{$metadata['anio']}c{$metadata['cuatrimestre']}-{$sede}-{$curso}-{$num}";
    }
    
    //Inserta el registro del repositorio en la base de datos
    private function insertRepositoryRecord(string $repo_name, string $sede, string $curso, int $num_grupo): void {
        global $DB;
        
        $DB->insert_record('repositorios_data_patroller', (object) [
            'nombre_repo' => $repo_name,  // Nombre completo para GitHub
            'sede' => $sede,               // Para filtros y agrupación
            'curso' => $curso,             // Para filtros y agrupación
            'num_grupo' => $num_grupo,     // Número secuencial del grupo
            'id_materia' => $this->course->id, // Asociar al curso actual
        ]);
    }

    //Elimina un repositorio de la DB y de github
    public function deleteRepository(int $repo_id, int $course_id): array {
        global $DB;
        
        if ($repo_id <= 0) {
            return [
                'success' => false,
                'message' => 'ID de repositorio inválido'
            ];
        }
        
        // Verificar que el repositorio existe y pertenece al curso actual
        $repo = $DB->get_record('repositorios_data_patroller', [
            'id' => $repo_id,
            'id_materia' => $course_id
        ]);
        
        if (!$repo) {
            return [
                'success' => false,
                'message' => 'Repositorio no encontrado o no pertenece a este curso'
            ];
        }
        
        $repo_name = $repo->nombre_repo;
        
        try {
            $this->liberateStudentsFromRepository($repo_id, $course_id);

            $github_deleted = $this->deleteRepositoryFromGitHub($repo_name);
            
            $DB->delete_records('repositorios_data_patroller', ['id' => $repo_id]);
            
            $message = "Repositorio '$repo_name' eliminado exitosamente. Los estudiantes han sido liberados.";
            if ($github_deleted) {
                $message .= " También fue eliminado de GitHub.";
            }
            
            return [
                'success' => true,
                'message' => $message,
                'repo_name' => $repo_name
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al eliminar repositorio: ' . $e->getMessage()
            ];
        }
    }

    //Libera a los estudiantes asignados a un repositorio 
    private function liberateStudentsFromRepository(int $repo_id, int $course_id): void {
        global $DB;
        
        $DB->execute(
            "UPDATE {usuarios_data_patroller}
            SET id_repo = 0, invitacion_status = 0
            WHERE id_repo = ? AND id_materia = ?",
            [$repo_id, $course_id]
        );
    }

    //Elimina un repositorio de GitHub
    private function deleteRepositoryFromGitHub(string $repo_name): bool {
        try {
            $result = $this->githubAPI->deleteRepository($repo_name);
            return true;
            
        } catch (\Exception $e) {
            return false;
        }
    }
}
