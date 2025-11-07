<?php
namespace mod_pluginpatroller\controllers;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\service\TeacherRepoService;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;

/**
 * Controlador para la gestión de repositorios de profesores
 */
class TeacherReposController extends AbstractController {
    
    /**
     * Ejecuta la lógica del controlador (SOLO RENDERIZADO)
     * 
     * @return string HTML renderizado
     */
    public function execute(): string {
        // DEPRECATED: Usar executeView() en su lugar
        return $this->executeView();
    }
    
    /**
     * Renderiza la vista (sin procesar POST)
     * 
     * @return string HTML renderizado
     */
    public function executeView(): string {
        global $OUTPUT, $USER;
        
        try {
            // Crear servicio
            $service = new TeacherRepoService($this->course, $USER);
            
            // Obtener datos de repositorios
            $data = $service->getRepositoriesWithStatus();
            
            // Añadir información adicional para la vista
            $data['courseid'] = $this->course->id;
            $data['cmid'] = $this->cm->id;
            $data['sesskey'] = sesskey();
            
            // Convertir valores booleanos para Mustache
            $data['has_repos'] = !empty($data['has_repos']) ? true : false;
            $data['has_valid_username'] = !empty($data['has_valid_username']) ? true : false;
            $data['can_assign'] = !empty($data['can_assign']) ? true : false;
            $data['has_deleted_repos'] = !empty($data['has_deleted_repos']) ? true : false;
            
            // Asegurar que los arrays existen
            $data['my_repos'] = $data['my_repos'] ?? [];
            $data['unassigned_repos'] = $data['unassigned_repos'] ?? [];
            $data['other_teachers_repos'] = $data['other_teachers_repos'] ?? [];
            $data['deleted_repos'] = $data['deleted_repos'] ?? [];
            
            // Añadir banderas para Mustache
            $data['has_my_repos'] = !empty($data['my_repos']);
            $data['has_unassigned_repos'] = !empty($data['unassigned_repos']);
            $data['has_other_teachers_repos'] = !empty($data['other_teachers_repos']);
            
            // Renderizar plantilla
            $html = $OUTPUT->render_from_template('mod_pluginpatroller/teacher_repos', $data);
            
            return $html;
            
        } catch (\Exception $e) {
            // Retornar mensaje de error
            return $OUTPUT->render_from_template('mod_pluginpatroller/error', [
                'error_title' => 'Error al cargar repositorios',
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Procesa la solicitud POST (sin renderizar)
     * Este método debe ser llamado ANTES del header
     */
    public function handlePostRequest(): void {
        global $USER;
        
        require_sesskey();
        
        $action = optional_param('action', '', PARAM_ALPHA);
        
        $service = new TeacherRepoService($this->course, $USER);
        
        // IMPORTANTE: Los nombres deben coincidir EXACTAMENTE con los del formulario
        switch ($action) {
            case 'savegithubusername':  // SIN GUION BAJO (PARAM_ALPHA elimina guiones bajos)
                $this->handleSaveGithubUsername($service);
                break;
                
            case 'assignmultiplerepos':  // NUEVA ACCIÓN
                $this->handleAssignMultipleRepos($service);
                break;
                
            case 'unassignrepo':  // SIN GUION BAJO
                $this->handleUnassignRepo($service);
                break;
                
            case 'syncinvitations':  // SIN GUION BAJO
                $this->handleSyncInvitations($service);
                break;
                
            default:
                \core\notification::error('Acción no reconocida: ' . $action);
        }
    }
    
    /**
     * Maneja el guardado del username de GitHub
     */
    private function handleSaveGithubUsername(TeacherRepoService $service): void {
        $github_username = required_param('github_username', PARAM_ALPHANUMEXT);
        
        $result = $service->saveGithubUsername($github_username);
        
        if ($result === true) {
            // NO mostramos success aquí porque el servicio ya mostró warning si cambió username
            // Solo mostramos success si es la primera vez o no cambió
            // El servicio ya agregó la notificación si correspondía
        } else {
            \core\notification::error($result);
        }
    }
    
    /**
     * Maneja la asignación múltiple de repositorios
     */
    private function handleAssignMultipleRepos(TeacherRepoService $service): void {
        try {
            // DIAGNÓSTICO INICIAL
            // INICIALIZAR variable
            $repo_ids = [];
            
            // PASO 1: Intentar obtener desde JSON (NUEVO - prioridad 1)
            $repo_ids_json = optional_param('repo_ids_json', '', PARAM_RAW);
            
            if (!empty($repo_ids_json)) {
                $decoded = json_decode($repo_ids_json, true);
                
                if (is_array($decoded)) {
                    // Convertir strings a integers
                    $repo_ids = array_map('intval', $decoded);
                }
            }
            
            // PASO 2: Si JSON falló, intentar con array tradicional
            if (empty($repo_ids)) {
                $repo_ids_array = optional_param_array('repo_ids', [], PARAM_INT);
                
                if (!empty($repo_ids_array)) {
                    $repo_ids = $repo_ids_array;
                }
            }
            
            // PASO 3: Si array falló, intentar con string separado por comas
            if (empty($repo_ids)) {
                $repo_ids_single = optional_param('repo_ids', '', PARAM_TEXT);
                
                if (!empty($repo_ids_single)) {
                    $repo_ids = array_map('intval', explode(',', $repo_ids_single));
                }
            }
            
            // PASO 4: Último recurso - acceso directo a $_POST
            if (empty($repo_ids) && isset($_POST['repo_ids']) && is_array($_POST['repo_ids'])) {
                $repo_ids = array_map('intval', $_POST['repo_ids']);
            }
            
            // VALIDACIÓN: Array vacío
            if (empty($repo_ids)) {
                \core\notification::error('No se seleccionaron repositorios para asignar');
                return;
            }
            
            // VALIDACIÓN: Límite
            $max_repos = 10;
            if (count($repo_ids) > $max_repos) {
                \core\notification::error("Máximo {$max_repos} repositorios por operación. Seleccionaste " . count($repo_ids));
                return;
            }
            
            // Llamar al servicio
            $result = $service->assignMultipleRepositories($repo_ids);
            
            // Mostrar resultado
            if ($result['success']) {
                $message = sprintf(
                    'Operación completada: %d repositorio(s) procesado(s), %d asignado(s) correctamente',
                    $result['total'],
                    $result['assigned']
                );
                
                \core\notification::success($message);
                
                // Mostrar detalles
                if (!empty($result['assigned_repos'])) {
                    foreach ($result['assigned_repos'] as $assigned) {
                        \core\notification::info(
                            "{$assigned['repo_name']}: {$assigned['message']}"
                        );
                    }
                }
                
                if (!empty($result['already_assigned_repos'])) {
                    foreach ($result['already_assigned_repos'] as $already) {
                        \core\notification::warning(
                            "{$already['repo_name']}: Ya estaba asignado"
                        );
                    }
                }
                
                if (!empty($result['error_repos'])) {
                    foreach ($result['error_repos'] as $error) {
                        \core\notification::error(
                            "{$error['repo_name']}: {$error['error']}"
                        );
                    }
                }
                
            } else {
                \core\notification::error(
                    $result['message'] ?? 'Error al asignar repositorios'
                );
            }
            
        } catch (\Exception $e) {
            \core\notification::error(
                'Error al asignar repositorios: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Maneja la desasignación de un repositorio
     */
    private function handleUnassignRepo(TeacherRepoService $service): void {
        $repo_id = required_param('repo_id', PARAM_INT);
        
        $result = $service->unassignRepository($repo_id);
        
        if ($result['success']) {
            \core\notification::success($result['message']);
        } else {
            \core\notification::error($result['message']);
        }
    }
    
    /**
     * Maneja la sincronización de invitaciones con GitHub
     */
    private function handleSyncInvitations(TeacherRepoService $service): void {
        try {
            $result = $service->syncInvitationStatuses();
            
            if ($result['success']) {
                $message = sprintf(
                    'Sincronización completada: %d repositorios procesados, %d actualizados, %d sin cambios',
                    $result['total_repos'],
                    $result['updated'],
                    $result['unchanged']
                );
                
                \core\notification::success($message);
                
                // Mostrar detalles de repositorios actualizados
                if (!empty($result['updated_repos'])) {
                    foreach ($result['updated_repos'] as $update) {
                        \core\notification::info(
                            "{$update['repo_name']}: {$update['old_status_text']} → {$update['new_status_text']}"
                        );
                    }
                }
                
                // Mostrar errores si los hay
                if ($result['errors'] > 0 && !empty($result['error_repos'])) {
                    foreach ($result['error_repos'] as $error) {
                        \core\notification::warning(
                            "Error en {$error['repo_name']}: {$error['error']}"
                        );
                    }
                }
                
            } else {
                \core\notification::error(
                    $result['message'] ?? 'Error al sincronizar invitaciones'
                );
            }
            
        } catch (\Exception $e) {
            \core\notification::error(
                'Error al sincronizar invitaciones: ' . $e->getMessage()
            );
        }
    }
}
