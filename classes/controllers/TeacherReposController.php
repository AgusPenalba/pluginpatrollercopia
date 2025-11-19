<?php
namespace mod_pluginpatroller\controllers;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\service\view\TeacherRepoService;
use mod_pluginpatroller\service\view\TeacherRepoAssignmentService;
use mod_pluginpatroller\service\view\TeacherRepoStatusService;
use mod_pluginpatroller\service\view\TemplateDataService;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;
use mod_pluginpatroller\helpers\ConfigHelper;

//Controlador para la gestión de repositorios de profesores
class TeacherReposController extends AbstractController {
    
    // Renderiza la vista de gestión de repositorios para profesores
    public function execute(): string {
        global $OUTPUT, $USER;
        
        try {
            // Crear servicios
            $usernameService = new TeacherRepoService($this->course, $USER);
            $templateDataService = $this->createTemplateDataService();
            
            // Inicializar GitHub API
            $apiService = new \mod_pluginpatroller\service\github\GitHubApiService();
            $githubAPI = $apiService->getAPI();
            
            $statusService = new TeacherRepoStatusService($this->course, $USER, $githubAPI);
            
            // Obtener username de GitHub
            $github_username = $usernameService->getGithubUsername();
            
            // Obtener y preparar datos de repositorios
            $raw_data = $statusService->getRepositoriesWithStatus($github_username);
            $data = $statusService->prepareViewData($raw_data, $this->cm, $templateDataService);
            
            // Renderizar plantilla
            return $OUTPUT->render_from_template('mod_pluginpatroller/teacher_repos', $data);
            
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }
    
    //Procesa la solicitud POST (sin renderizar)
    public function handlePostRequest(): void {
        global $USER;
        
        require_sesskey();
        
        $action = optional_param('action', '', PARAM_ALPHA);
        
        // Crear servicios
        $usernameService = new TeacherRepoService($this->course, $USER);
        
        // Inicializar GitHub API
        $apiService = new \mod_pluginpatroller\service\github\GitHubApiService();
        $githubAPI = $apiService->getAPI();
        
        $assignmentService = new TeacherRepoAssignmentService($this->course, $USER, $githubAPI);
        $statusService = new TeacherRepoStatusService($this->course, $USER, $githubAPI);
        
        // IMPORTANTE: Los nombres deben coincidir EXACTAMENTE con los del formulario
        switch ($action) {
            case 'savegithubusername':  // SIN GUION BAJO (PARAM_ALPHA elimina guiones bajos)
                $this->handleSaveGithubUsername($usernameService);
                break;
                
            case 'assignmultiplerepos':  // NUEVA ACCIÓN
                $this->handleAssignMultipleRepos($assignmentService, $usernameService);
                break;
                
            case 'unassignrepo':  // SIN GUION BAJO
                $this->handleUnassignRepo($assignmentService);
                break;
                
            case 'syncinvitations':  // SIN GUION BAJO
                $this->handleSyncInvitations($statusService, $usernameService);
                break;
                
            default:
                \core\notification::error('Acción no reconocida: ' . $action);
        }
    }
    
    //Maneja el guardado del username de GitHub
    private function handleSaveGithubUsername(TeacherRepoService $service): void {
        $github_username = required_param('github_username', PARAM_ALPHANUMEXT);
        
        $result = $service->saveGithubUsername($github_username);
        
        if ($result['success']) {
            // Mostrar mensaje apropiado según el caso
            if (!empty($result['username_changed'])) {
                // Cambio de username - mostrar advertencia
                \core\notification::warning($result['message']);
            } else {
                // Primera vez o sin cambios - mostrar éxito
                \core\notification::success($result['message']);
            }
        } else {
            // Error - mostrar mensaje de error
            \core\notification::error($result['message']);
        }
    }
    
    //Maneja la asignación múltiple de repositorios
    private function handleAssignMultipleRepos(TeacherRepoAssignmentService $assignmentService, TeacherRepoService $usernameService): void {
        try {
            // Parsear IDs de repositorios
            $repo_ids = $this->parseRepoIds();
            
            if (empty($repo_ids)) {
                \core\notification::error('No se seleccionaron repositorios para asignar');
                return;
            }
            
            $max_repos = 10;
            if (count($repo_ids) > $max_repos) {
                \core\notification::error("Máximo {$max_repos} repositorios por operación. Seleccionaste " . count($repo_ids));
                return;
            }
            
            $github_username = $usernameService->getGithubUsername();
            
            // Ejecutar asignación
            $result = $assignmentService->assignMultipleRepositories($repo_ids, $github_username);
            
            // Delegar notificaciones al servicio
            $assignmentService->notifyAssignmentResult($result);
            
        } catch (\Exception $e) {
            \core\notification::error(
                'Error al asignar repositorios: ' . $e->getMessage()
            );
        }
    }
    
    //Maneja la desasignación de un repositorio
    private function handleUnassignRepo(TeacherRepoAssignmentService $assignmentService): void {
        $repo_id = required_param('repo_id', PARAM_INT);
        
        $result = $assignmentService->unassignRepository($repo_id);
        
        // Delegar notificación al servicio
        $assignmentService->notifyUnassignmentResult($result);
    }
    
    //Maneja la sincronización de invitaciones con GitHub
    private function handleSyncInvitations(TeacherRepoStatusService $statusService, TeacherRepoService $usernameService): void {
        try {
            // Obtener username de GitHub
            $github_username = $usernameService->getGithubUsername();
            
            $result = $statusService->syncInvitationStatuses($github_username);
            
            // Delegar notificaciones al servicio
            $statusService->notifySyncResult($result);
            
        } catch (\Exception $e) {
            \core\notification::error(
                'Error al sincronizar invitaciones: ' . $e->getMessage()
            );
        }
    }
    
    // Parsea los IDs de repositorios desde diferentes formatos de entrada
    private function parseRepoIds(): array {
        $repo_ids = [];
        
        // Prioridad 1: Intentar obtener desde JSON
        $repo_ids_json = optional_param('repo_ids_json', '', PARAM_RAW);
        
        if (!empty($repo_ids_json)) {
            $decoded = json_decode($repo_ids_json, true);
            
            if (is_array($decoded)) {
                $repo_ids = array_map('intval', $decoded);
                return $repo_ids;
            }
        }
        
        // Prioridad 2: Array tradicional
        $repo_ids_array = optional_param_array('repo_ids', [], PARAM_INT);
        
        if (!empty($repo_ids_array)) {
            return $repo_ids_array;
        }
        
        // Prioridad 3: String separado por comas
        $repo_ids_single = optional_param('repo_ids', '', PARAM_TEXT);
        
        if (!empty($repo_ids_single)) {
            return array_map('intval', explode(',', $repo_ids_single));
        }
        
        // Prioridad 4: Acceso directo a $_POST
        if (isset($_POST['repo_ids']) && is_array($_POST['repo_ids'])) {
            return array_map('intval', $_POST['repo_ids']);
        }
        
        return [];
    }
}
