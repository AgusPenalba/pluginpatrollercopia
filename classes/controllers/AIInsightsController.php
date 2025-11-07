<?php
namespace mod_pluginpatroller\controllers;

use mod_pluginpatroller\ai\AIService;
use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\helpers\FilterHelper;
use mod_pluginpatroller\helpers\UserHelper;
use mod_pluginpatroller\model\UserModel;
use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;

defined('MOODLE_INTERNAL') || die();

/**
 * Controlador para análisis de IA de estudiantes
 * Interfaz de dos columnas: selección de estudiantes + resultados detallados
 */
class AIInsightsController extends AbstractController {
    
    private $aiService;
    
    public function execute(): string {
        $this->requireTeacherPermissions();
        $this->initializeServices();
        
        try {
            $success_message = '';
            
            // Procesar análisis específico de estudiante si se solicita
            $analyze_student = $this->getParam('analyze_student');
            if ($analyze_student) {
                $analysis_result = $this->performStudentAnalysis($analyze_student);
                if ($analysis_result) {
                    $success_message = "✅ Análisis completado para estudiante ID: $analyze_student";
                }
            }
            
            // Preparar datos para el template
            $data = $this->prepareTemplateData($success_message);
            
            return $this->render('ai_insights', $data);
            
        } catch (\Exception $e) {
            error_log("PLUGIN ERROR: Error en AIInsightsController: " . $e->getMessage());
            return $this->handleError($e);
        }
    }
    
    /**
     * Inicializar servicios necesarios
     */
    private function initializeServices(): void {
        $this->aiService = new AIService();
    }
    
    /**
     * Realizar análisis de IA para un estudiante específico
     */
    private function performStudentAnalysis($student_id): ?array {
        global $DB;
        
        try {
            // Obtener datos del estudiante
            $student_record = $DB->get_record('usuarios_data_patroller', [
                'id' => $student_id,
                'id_materia' => $this->course->id
            ]);
            
            if (!$student_record) {
                throw new \Exception("Estudiante no encontrado: ID $student_id");
            }
            
            // Obtener datos del usuario de Moodle
            $user_data = $DB->get_record('user', ['id' => $student_record->id_usuario]);
            if (!$user_data) {
                throw new \Exception("Usuario de Moodle no encontrado para estudiante ID $student_id");
            }
            
            // Obtener datos del repositorio
            $repository_record = $DB->get_record('repositorios_data_patroller', [
                'id' => $student_record->id_repo
            ]);
            
            if (!$repository_record) {
                throw new \Exception("Repositorio no encontrado para estudiante ID $student_id");
            }
            
            // Simular datos de commits (en implementación real obtener de GitHub API)
            $commits_data = $this->getCommitsData($repository_record->nombre_repo, $student_record->usuario_github);
            
            // Preparar datos para el análisis
            $student_data = [
                'id' => $student_record->id,
                'firstname' => $user_data->firstname,
                'lastname' => $user_data->lastname,
                'username' => $user_data->username,
                'github_username' => $student_record->usuario_github
            ];
            
            $repository_data = [
                'id' => $repository_record->id,
                'name' => $repository_record->nombre_repo
            ];
            
            // Realizar análisis con IA
            $analysis_result = $this->aiService->analyzeStudent($student_data, $repository_data, $commits_data);
            
            // Guardar resultado en sesión para mostrar en interfaz
            $_SESSION['last_ai_analysis'] = $analysis_result;
            
            return $analysis_result;
            
        } catch (\Exception $e) {
            error_log("PLUGIN AI ERROR: Error analizando estudiante $student_id: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Obtener datos reales de commits desde GitHub API
     */
    private function getCommitsData($repository_name, $github_username): array {
        try {
            // Obtener configuración de GitHub
            $owner = ConfigHelper::getGitHubOwner();
            $token = ConfigHelper::getGitHubToken();
            
            if (empty($owner) || empty($token)) {
                error_log("PLUGIN AI WARNING: GitHub API no configurada. Usando datos simulados.");
                return $this->getFallbackCommitsData($github_username);
            }
            
            // Crear instancia de la API de GitHub
            $githubAPI = new GitHubPatrollerAPI($owner, $token);
            
            // Obtener commits del usuario en el repositorio (últimos 30 días)
            $since_date = date('c', strtotime('-30 days')); // Formato ISO8601
            $github_commits = [];
            
            if (!empty($github_username)) {
                // Obtener commits específicos del usuario
                $github_commits = $githubAPI->getCommitsByRepoAndUser($repository_name, $github_username, $since_date);
            }
            
            // Si no hay commits del usuario específico o no se especificó usuario, obtener commits generales
            if (empty($github_commits)) {
                error_log("PLUGIN AI INFO: No se encontraron commits específicos de '$github_username' en $repository_name");
                // Usar método más general si existe, o fallback
                return $this->getFallbackCommitsData($github_username);
            }
            
            // Convertir commits de GitHub al formato esperado
            $commits_data = [];
            foreach (array_slice($github_commits, 0, 10) as $commit) { // Límite de 10 commits
                try {
                    // Obtener estadísticas detalladas del commit
                    $commit_stats = $githubAPI->getCommitStats($repository_name, $commit['sha']);
                    
                    $commits_data[] = [
                        'message' => $commit['commit']['message'] ?? 'Sin mensaje',
                        'files_changed' => count($commit_stats['files'] ?? []),
                        'lines_added' => $commit_stats['stats']['additions'] ?? 0,
                        'lines_deleted' => $commit_stats['stats']['deletions'] ?? 0,
                        'date' => $commit['commit']['author']['date'] ?? date('Y-m-d H:i:s'),
                        'author' => $commit['author']['login'] ?? $commit['commit']['author']['name'] ?? 'Unknown',
                        'sha' => $commit['sha'] ?? ''
                    ];
                } catch (\Exception $commit_error) {
                    // Si hay error con un commit específico, usar datos básicos
                    $commits_data[] = [
                        'message' => $commit['commit']['message'] ?? 'Sin mensaje',
                        'files_changed' => 1, // Estimación
                        'lines_added' => 10, // Estimación
                        'lines_deleted' => 2, // Estimación
                        'date' => $commit['commit']['author']['date'] ?? date('Y-m-d H:i:s'),
                        'author' => $commit['author']['login'] ?? $commit['commit']['author']['name'] ?? 'Unknown',
                        'sha' => $commit['sha'] ?? ''
                    ];
                }
            }
            
            if (empty($commits_data)) {
                error_log("PLUGIN AI INFO: No se pudieron procesar commits de $repository_name. Usando fallback.");
                return $this->getFallbackCommitsData($github_username);
            }
            
            error_log("PLUGIN AI INFO: Obtenidos " . count($commits_data) . " commits reales de $repository_name para análisis.");
            return $commits_data;
            
        } catch (\Exception $e) {
            error_log("PLUGIN AI ERROR: Error obteniendo commits de GitHub: " . $e->getMessage());
            return $this->getFallbackCommitsData($github_username);
        }
    }
    
    /**
     * Datos de fallback cuando no se puede acceder a GitHub API
     */
    private function getFallbackCommitsData($github_username): array {
        return [
            [
                'message' => 'update',
                'files_changed' => 1,
                'lines_added' => 5,
                'lines_deleted' => 2,
                'date' => date('Y-m-d H:i:s', strtotime('-2 days')),
                'author' => $github_username ?: 'estudiante'
            ],
            [
                'message' => 'fix',
                'files_changed' => 2,
                'lines_added' => 3,
                'lines_deleted' => 1,
                'date' => date('Y-m-d H:i:s', strtotime('-5 days')),
                'author' => $github_username ?: 'estudiante'
            ]
        ];
    }
    
    /**
     * Preparar todos los datos para el template
     */
    private function prepareTemplateData(string $success_message = ''): array {
        // Configurar filtros para estudiantes
        $filter_config = [
            'show_sede' => true,
            'show_curso' => true,
            'show_repo' => true
        ];
        $filters_data = FilterHelper::getFiltersData($this->course->id, $filter_config);
        
        // Obtener lista de estudiantes
        $students_data = $this->getStudentsForAnalysis();
        
        // Obtener último análisis si existe
        $last_analysis = $_SESSION['last_ai_analysis'] ?? null;
        
        // Información del proveedor de IA
        $ai_provider_info = $this->aiService->getProviderInfo();
        
        return [
            // Header del template
            'header_icon' => 'fas fa-brain',
            'header_title' => 'AI Analysis Dashboard',
            'header_subtitle' => 'AI Demo - Análisis de Commits',
            
            // IDs necesarios
            'cm_id' => $this->cm->id,
            
            // Mensajes y estado
            'success_message' => $success_message,
            'has_success' => !empty($success_message),
            
            // Información de demostración
            'demo_message' => 'Prueba básica: Selecciona un estudiante de la tabla y haz clic en "Analizar" para ver un análisis detallado.',
            
            // Sección de estudiantes
            'filters_config' => $filters_data,
            'table_id' => 'studentsAnalysisTable',
            'students' => $students_data,
            'has_students' => !empty($students_data),
            
            // Resultados del análisis
            'analysis_result' => $last_analysis,
            'has_analysis' => !empty($last_analysis),
            
            // Información del proveedor de IA
            'ai_provider' => $ai_provider_info,
            'is_using_real_ai' => $this->aiService->isUsingRealAI(),
            
            // Estados del template
            'show_analysis_panel' => true
        ];
    }
    
    /**
     * Obtener estudiantes disponibles para análisis
     */
    private function getStudentsForAnalysis(): array {
        global $DB;
        
        $sql = "SELECT u.id as user_id, u.firstname, u.lastname, u.username,
                       udp.id, udp.usuario_github, udp.invitacion_status,
                       rdp.nombre_repo, rdp.id as repo_id
                FROM {usuarios_data_patroller} udp
                JOIN {user} u ON udp.id_usuario = u.id
                JOIN {repositorios_data_patroller} rdp ON udp.id_repo = rdp.id
                WHERE udp.id_materia = ?
                AND udp.usuario_github IS NOT NULL 
                AND udp.usuario_github != ''
                ORDER BY u.firstname, u.lastname";
        
        $records = $DB->get_records_sql($sql, [$this->course->id]);
        
        $students = [];
        foreach ($records as $record) {
            $students[] = [
                'id' => $record->id,
                'user_id' => $record->user_id,
                'firstname' => $record->firstname,
                'lastname' => $record->lastname,
                'username' => $record->username,
                'github_username' => $record->usuario_github,
                'repository_name' => $record->nombre_repo,
                'repository_id' => $record->repo_id,
                'full_name' => trim($record->firstname . ' ' . $record->lastname),
                'invitation_status' => $record->invitacion_status,
                'can_analyze' => !empty($record->usuario_github)
            ];
        }
        
        return $students;
    }
}