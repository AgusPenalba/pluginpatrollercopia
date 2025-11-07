<?php
namespace mod_pluginpatroller\service;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\helpers\UserHelper;

/**
 * Servicio especializado para operaciones con repositorios GitHub
 * Extrae lógica compleja del MainPanelController
 */
class RepositoryCreationService {
    
    private $course;
    private $githubAPI;
    
    public function __construct($course, GitHubPatrollerAPI $githubAPI) {
        $this->course = $course;
        $this->githubAPI = $githubAPI;
    }
    
    /**
     * Procesa la creación masiva de repositorios según la selección del formulario
     */
    public function processCreateRepos(array $selected_groups, array $repos_to_add, array $users_by_repo): void {
    // debug traces removed
        
        if (empty($selected_groups)) {
            return;
        }
        
        $course_metadata = $this->parseCourseMetadata();
        
        foreach ($selected_groups as $group_key) {
            $to_create = (int)($repos_to_add[$group_key] ?? 0);
            
            if ($to_create <= 0) {
                continue;
            }
            
            $this->createRepositoriesForGroup($group_key, $to_create, $users_by_repo, $course_metadata);
        }
        
    // debug traces removed
    }
    
    /**
     * Obtiene el último número de repositorio para un grupo específico
     */
    public function getLastRepoNumForGroup(array $users_by_repo, string $sede, string $curso): int {
        $max = 0;
        foreach ($users_by_repo as $repo) {
            if ($repo['sede'] === $sede && $repo['curso'] === $curso && (int)$repo['nro'] > $max) {
                $max = (int)$repo['nro'];
            }
        }
        return $max;
    }
    
    /**
     * Obtiene repositorios con estudiantes anidados de forma optimizada
     */
    public function getReposWithNestedStudents(): array {
        global $DB;
        $repos_con_alumnos = [];
        
        $repositorios = $DB->get_records('repositorios_data_patroller', ['id_materia' => $this->course->id]);
        
        foreach ($repositorios as $r) {
            $students = \mod_pluginpatroller\model\UserModel::get_students_by_repo($r->id, $this->course->id);
            
            // Debug: log de invitacion_status de cada estudiante
            // debug traces removed
            
            $repos_con_alumnos[$r->id] = [
                'id' => $r->id,
                'nombre_repo' => $r->nombre_repo,
                'curso' => $r->curso,
                'sede' => $r->sede,
                'nro' => $r->num_grupo,
                'students' => $students,
            ];
        }
        
        return $repos_con_alumnos;
    }
    
    // Métodos privados para encapsular lógica específica
    
    private function parseCourseMetadata(): array {
        $course_parts = explode('-', $this->course->shortname);
        return [
            'cuatrimestre' => $course_parts[2] ?? '',
            'anio' => $course_parts[1] ?? '',
            'prefix' => $course_parts[0] ?? 'prf'
        ];
    }
    
    private function createRepositoriesForGroup(string $group_key, int $to_create, array $users_by_repo, array $metadata): void {
        [$sede, $curso] = UserHelper::splitGroupKey($group_key);
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
    
    private function generateRepoName(array $metadata, string $sede, string $curso, int $num): string {
        return "{$metadata['prefix']}-{$metadata['anio']}c{$metadata['cuatrimestre']}-{$sede}-{$curso}-{$num}";
    }
    
    private function insertRepositoryRecord(string $repo_name, string $sede, string $curso, int $num_grupo): void {
        global $DB;
        $DB->insert_record('repositorios_data_patroller', (object) [
            'nombre_repo' => $repo_name,
            'sede' => $sede,
            'curso' => $curso,
            'num_grupo' => $num_grupo,
            'id_materia' => $this->course->id,
        ]);
    }
}
