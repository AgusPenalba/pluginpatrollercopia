<?php
namespace mod_pluginpatroller\service;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\UserModel;
use mod_pluginpatroller\service\CommitService;
use mod_pluginpatroller\service\GradebookService;
use mod_pluginpatroller\service\GitHubAccessService;
use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\service\StatisticsService;

/**
 * Servicio especializado para insights de contribuciones y calificaciones
 * Maneja actualización de commits y asignación de calificaciones
 */
class ContributorInsightsService {
    private $course;
    private $pluginpatroller;
    private CommitService $commitService;
    private GradebookService $gradebookService;
    private StatisticsService $statisticsService;

    public function __construct($course, $pluginpatroller) {
        $this->course = $course;
        $this->pluginpatroller = $pluginpatroller;
        $this->commitService = new CommitService();
        $this->gradebookService = new GradebookService();
        $this->statisticsService = new StatisticsService();
    }

    // Solo delega la actualización
    public function updateCommits(): void {
        $this->commitService->updateCommits($this->course->id);
    }
    
    /**
     * Procesa calificaciones guardadas desde el formulario
     */
    public function processSaveGrades(array $grades): void {
        global $DB;
        foreach ($grades as $student_id => $grade) {
            if (is_numeric($grade) && $grade >= 0 && $grade <= 10) {
                GradebookService::updateStudentGrade(
                    (int)$student_id,
                    $this->course->id,
                    (int)$grade
                );
                // Traer y loguear la calificación actualizada de la base
                $record = $DB->get_record('usuarios_data_patroller', [
                    'id_usuario' => (int)$student_id,
                    'id_materia' => $this->course->id
                ]);
                $calificacion = $record ? $record->calificacion : 'NO ENCONTRADO';
            }
        }
    }
    
    /**
     * Obtiene datos completos de estudiantes con commits y calificaciones
     */
    public function getStudentsCommitData(?int $repo_filter = null): array {
        global $DB;
        
        $where_clause = ['id_materia' => $this->course->id];
        if ($repo_filter && $repo_filter !== 0) {
            $where_clause['id_repo'] = $repo_filter;
        }
        
        $students = $DB->get_records('usuarios_data_patroller', $where_clause);
        return array_map([$this, 'formatStudentCommitData'], $students);
    }
    
    /**
     * Calcula estadísticas agregadas de commits
     */
    public function calculateCommitStatistics(array $students_data): array {
        // Delegate to StatisticsService for consistency across views
        return $this->statisticsService->calculateFromStudents($students_data);
    }
    
    /**
     * Obtiene opciones de repositorio para filtros
     */
    public function getRepositoryFilterOptions(): array {
        global $DB;
        $repositories = $DB->get_records('repositorios_data_patroller', 
            ['id_materia' => $this->course->id], 
            'nombre_repo ASC'
        );
        $options = [];
        $options[0] = 'Todos los repositorios';
        foreach ($repositories as $repo) {
            $options[$repo->id] = $repo->nombre_repo;
        }
        return $options;
    }
    
    // Métodos privados para formateo de datos
    
    private function formatStudentCommitData(object $student): array {
        global $DB;

        // Repositorio
        $repo = $DB->get_record('repositorios_data_patroller', ['id' => $student->id_repo]);
        $repo_name = $repo ? $repo->nombre_repo : 'Sin asignar';

        // Usuario Moodle
        $user = $DB->get_record('user', ['id' => $student->id_usuario]);
        $full_name = $user ? ($user->firstname . ' ' . $user->lastname) : $student->nombre_usuario;

        // Calificación
        $grade = GradebookService::getStudentGrade($student->id_usuario, $this->course->id);
        $grade_value = $grade !== null ? $grade : '';

        // Opciones de calificación (0-10)
        $grade_options = [];
        for ($i = 0; $i <= 10; $i++) {
            $grade_options[] = [
                'value' => $i,
                'is_selected' => ($grade_value !== '' && (int)$grade_value === $i)
            ];
        }
        $owner = ConfigHelper::getGithubOwner();
        // Construir repository_url defensivamente
        $repository_url = '';
        if ($repo && !empty($repo->nombre_repo)) {
            $repo_name = $repo->nombre_repo;
            if (strpos($repo_name, '/') !== false) {
                $repository_url = 'https://github.com/' . rawurlencode($repo_name);
            } elseif (!empty($owner)) {
                $repository_url = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo_name);
            }
        }

        // Enlaces a GitHub (construidos a partir del owner y el nombre del repo)
        $github_contributors_url = ($repository_url && $student->usuario_github)
            ? $repository_url . '/graphs/contributors'
            : '#';
        $github_commits_url = ($repository_url && $student->usuario_github)
            ? $repository_url . '/commits?author=' . rawurlencode($student->usuario_github)
            : '#';

        // Solo el nombre del icono para el template
        $github_logo = 'github';

        // Badge para commits
        $has_commits = ((int)$student->cantidad_commits > 0);

        return [
            'repo_name'             => $repo_name,
            'github_username'       => $student->usuario_github ?: 'Sin configurar',
            'full_name'             => $full_name,
            'last_commit_date'      => $this->formatLastCommitDate($student->fecha_ultimo_commit),
            'commit_count'          => (int)$student->cantidad_commits,
            'lines_added'           => (int)$student->lineas_agregadas,
            'lines_deleted'         => (int)$student->lineas_eliminadas,
            'lines_modified'        => (int)$student->lineas_modificadas,
            'grade'                 => $grade_value,
            'student_id'            => $student->id_usuario,
            'grade_empty'           => ($grade_value === ''),
            'grade_options'         => $grade_options,
            'github_contributors_url' => $github_contributors_url,
            'github_commits_url'    => $github_commits_url,
            'github_logo'           => $github_logo,
            'has_commits'           => $has_commits,
            'repository_url'        => $repository_url,
        ];
    }
    
    private function formatLastCommitDate(?string $date): string {
        if (!$date) return 'Nunca';
        
        $timestamp = strtotime($date);
        if (!$timestamp) return 'Fecha inválida';
        
        return date('d/m/Y H:i', $timestamp);
    }
        
    
}
