<?php
namespace mod_pluginpatroller\controllers;

use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\service\GitHubAccessService;
use mod_pluginpatroller\service\student\StudentManagementService;
use mod_pluginpatroller\service\view\TemplateDataService;
use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\helpers\FilterHelper;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;

defined('MOODLE_INTERNAL') || die();

class GestionAccesosController extends AbstractController {
    
    private $gitHubAccessService;
    private $studentManagementService;
    private $templateDataService;
    
    public function execute(): string {
        $this->requireTeacherPermissions();
        $this->initializeServices();
        
            try {
                $success_message = '';

                // Procesar test de API
                $test_api = $this->getParam('test_api');
                if ($test_api) {
                    try {
                        $test_result = $this->testGitHubAPI();
                        $success_message = "≡ƒº¬ TEST API RESULTADO:<br>" . $test_result;
                    } catch (\Exception $e) {
                        $success_message = "Γ¥î ERROR EN TEST API: " . $e->getMessage();
                    }
                }
            
                // Procesar invitaciones SOLO si se presionó el botón (campo send_invitations presente)
                $repository_selected = $this->getParam('repository_selected');
                $is_manual_send = $this->getParam('send_invitations');
                
                if ($repository_selected && $is_manual_send) {
                    try {
                        $force_resend = $this->getParam('force_resend') ? true : false;
                        $result = $this->gitHubAccessService->processInvitations($repository_selected, $force_resend);
                        
                        // Si es petición AJAX, devolver solo los datos actualizados
                        if ($this->isAjaxRequest()) {
                            $this->sendAjaxResponse([
                                'success' => true,
                                'message' => "Γ£à " . $result,
                                'students_data' => $this->getUpdatedStudentsData()
                            ]);
                            return '';
                        }
                        
                        // REDIRIGIR para evitar reenvío automático al refrescar la página
                        $this->redirect($this->getPluginUrl(['tab' => 'tab2']), "" . $result, 3);
                        
                    } catch (\Exception $e) {
                        if ($this->isAjaxRequest()) {
                            $this->sendAjaxResponse([
                                'success' => false,
                                'message' => "ERROR: " . $e->getMessage()
                            ]);
                            return '';
                        }
                        
                        $error_message = "ERROR: " . $e->getMessage();
                        $debug_info = $this->getDebugInfo($repository_selected);
                        $success_message = "PROCESANDO REPOSITORIO: '$repository_selected'<br>" . $debug_info . "<br><br>" . $error_message;
                    }
                }

                // Procesar sincronizaci├│n de invitaciones
                $sync_invitations = $this->getParam('sync_invitations');
                if ($sync_invitations) {
                    require_sesskey();
                    try {
                        // Primero inicializar estados faltantes
                        $init_result = $this->gitHubAccessService->initializeMissingStatuses($this->course->id);
                        
                        // Luego sincronizar con GitHub
                        $sync_result = $this->gitHubAccessService->syncInvitationStatuses($this->course->id);
                        
                        $message = "Sincronización completada:<br>";
                        $message .= "• Inicializados: {$init_result['initialized']} registros<br>";
                        $message .= "• Actualizados: {$sync_result['updated']} estados<br>";
                        $message .= "• Total procesados: {$sync_result['total']} estudiantes";
                        
                        if ($sync_result['errors'] > 0) {
                            $message .= "<br>• Errores: {$sync_result['errors']}";
                        }
                        
                        // Si es petición AJAX, devolver solo los datos actualizados
                        if ($this->isAjaxRequest()) {
                            error_log("DEBUG AJAX: Enviando respuesta de sincronización exitosa");
                            $this->sendAjaxResponse([
                                'success' => true,
                                'message' => $message,
                                'students_data' => $this->getUpdatedStudentsData()
                            ]);
                            return '';
                        }
                        
                        $this->redirect($this->getPluginUrl(['tab' => 'tab2']), $message, 3);
                        
                    } catch (\Exception $e) {
                        if ($this->isAjaxRequest()) {
                            $this->sendAjaxResponse([
                                'success' => false,
                                'message' => "Error en sincronización: " . $e->getMessage()
                            ]);
                            return '';
                        }
                        $this->redirect($this->getPluginUrl(['tab' => 'tab2']), "Error en sincronización: " . $e->getMessage(), 3);
                    }
                }

                // Procesar reset de invitaciones
                $reset_invitations = $this->getParam('reset_invitations');
                if ($reset_invitations) {
                    $this->resetInvitationStatuses();
                    $this->redirect($this->getPluginUrl(['tab' => 'tab2']), get_string('invitationsreset', 'mod_pluginpatroller'), 2);
                }

                // Procesar cambio de repositorio (SOLO si es POST con change_repo_user_id)
                $change_repo_user_id = $this->getParam('change_repo_user_id');
                $new_repo_name = $this->getParam('new_repo_name');
                
                
                if ($change_repo_user_id && $new_repo_name) {
                    error_log("DEBUG CAMBIO REPO: Entrando a handleRepositoryChange");
                    $this->handleRepositoryChange($change_repo_user_id);
                    $this->redirect($this->getPluginUrl(['tab' => 'tab2']), 'Repositorio cambiado exitosamente. El estudiante puede recibir una nueva invitación.', 3);
                }

                // Procesar envío de invitación individual
                $send_single_invitation = $this->getParam('send_single_invitation');
                if ($send_single_invitation) {
                    try {
                        $result = $this->gitHubAccessService->sendSingleInvitation($send_single_invitation);
                        if ($result['success']) {
                            $this->redirect($this->getPluginUrl(['tab' => 'tab2']), "" . $result['message'], 3);
                        } else {
                            $success_message = " " . $result['message'];
                        }
                    } catch (\Exception $e) {
                        $success_message = "Error al enviar invitación: " . $e->getMessage();
                    }
                }

                // Procesar cancelación de invitación
                $cancel_invitation = $this->getParam('cancel_invitation');
                if ($cancel_invitation) {
                    try {
                        $result = $this->gitHubAccessService->cancelInvitation($cancel_invitation);
                        if ($result['success']) {
                            // Redirigir para refrescar los datos y mostrar el nuevo estado
                            $this->redirect($this->getPluginUrl(['tab' => 'tab2']), "" . $result['message'], 3);
                        } else {
                            $this->redirect($this->getPluginUrl(['tab' => 'tab2']), "" . $result['message'], 3);
                        }
                    } catch (\Exception $e) {
                        $this->redirect($this->getPluginUrl(['tab' => 'tab2']), "Error al cancelar invitación: " . $e->getMessage(), 3);
                    }
                }

                // Procesar cambios si se enviaron
                $guardar_cambios = $this->getParam('guardar_cambios');
                if ($guardar_cambios) {
                    $this->processSaveChanges();
                    $this->redirect($this->getPluginUrl(['tab' => 'tab2']), get_string('changessaved', 'mod_pluginpatroller'), 2);
                }

                // Mensaje temporal si se está mostrando formulario de cambio
                $change_repo_for = $this->getParam('change_repo_for');
                if ($change_repo_for) {
                    $success_message = "Seleccione el nuevo repositorio para el estudiante (ID: $change_repo_for)";
                }

                // Preparar todos los datos para el template
                $data = $this->prepareTemplateData($success_message);
                $data['has_success'] = true;

                return $this->render('gestion_accesos', $data);

            } catch (\Exception $e) {
                error_log("PLUGIN ERROR: Error en GestionAccesosController: " . $e->getMessage());
                return $this->handleError($e);
            }
        }
    
        // Inicializa servicios especializados
        private function initializeServices(): void {
            $this->gitHubAccessService = new GitHubAccessService($this->course);
            $this->studentManagementService = new StudentManagementService($this->course);
            $this->templateDataService = new TemplateDataService($this->course, $this->cm);
        }
    
    // Procesa cambios usando el servicio especializado
    private function processSaveChanges(): void {
            $github_changes = $_POST['github'] ?? [];
            $repo_changes = $_POST['repositorio'] ?? [];
        
            $this->gitHubAccessService->processSaveChanges($github_changes, $repo_changes);
    }
    
    // Organiza toda la informacion para el template usando servicios
    private function prepareTemplateData(string $success_message = ''): array {
            // Cargar JavaScript externo para gestion de accesos
            global $PAGE;
            $PAGE->requires->js('/mod/pluginpatroller/scripts/gestion-accesos.js');
            
            // Configurar datos para filtros (SIN HTML)
            $filter_config = [
                'show_name' => true,
                'show_sede' => true,
                'show_curso' => true,
                'show_repo' => false
            ];

            // Usar el servicio de templates para preparar filtros y header
            $filters_data = $this->templateDataService->prepareFiltersConfig($filter_config);
            $header_data = $this->templateDataService->preparePageHeader('fas fa-key', get_string('accessmanagement', 'mod_pluginpatroller'), get_string('manageinvitationsrepos', 'mod_pluginpatroller'));

            // Obtener repositorios disponibles y formatear opciones (valor = nombre del repo para compatibilidad con processInvitations)
            $raw_repos = RepositoryModel::getAllByCourseId($this->course->id);
            $repository_options = [];
            // Opci├│n "All"
            $repository_options[] = [
                'value' => 'All',
                'text' => get_string('allrepositories', 'mod_pluginpatroller'),
                'selected' => false
            ];
            foreach ($raw_repos as $id => $name) {
                $repository_options[] = [
                    'value' => $name,
                    'text' => $name,
                    'selected' => false
                ];
            }

            // Obtener estudiantes usando el servicio (ya vienen formateados para la tabla)
            $students_data = $this->gitHubAccessService->getStudentsWithGitHubData($this->cm->id);
            $has_updatable_students = $this->gitHubAccessService->hasUpdatableStudents($students_data);

            // Detectar si se debe mostrar el formulario de cambio de repositorio
            $change_repo_for = $this->getParam('change_repo_for');
            
            // Agregar datos de cambio de repo a cada estudiante
            foreach ($students_data as &$student) {
                $student['show_change_form'] = ($change_repo_for && (int)$change_repo_for === (int)$student['user_id']);
                
                // Para el formulario de cambio: filtrar solo repos con espacio disponible (excluyendo el actual)
                if ($student['show_change_form']) {
                    $current_repo_name = $student['repository_name'] ?? null;
                    $student['available_repos'] = $this->getAvailableRepositoriesWithCapacity($raw_repos, $student['user_id'], $current_repo_name);
                } else {
                    $student['available_repos'] = $repository_options; // Todos los repos para filtros normales
                }
            }
            unset($student); // Romper referencia

            // Preparar variables esperadas por el template
            $table_id = 'access_table_' . $this->cm->id;

            // $filters_data viene con la forma ['filters' => [...], 'script_functions' => '...', 'table_id' => '...']
            $filters_list = is_array($filters_data) && isset($filters_data['filters']) ? $filters_data['filters'] : [];
            $filter_script = is_array($filters_data) && isset($filters_data['script_functions']) ? $filters_data['script_functions'] : '';

            return array_merge($header_data, [
                'cm_id' => $this->cm->id,
                'table_id' => $table_id,
                'filters_config' => true,
                'filters' => $filters_list,
                'filter_script' => $filter_script,
                'repository_options' => $repository_options,
                'students' => $students_data,
                'has_students' => !empty($students_data),
                'show_save_button' => $has_updatable_students,
                'success_message' => $success_message,
                'sesskey' => sesskey(), // Agregar sesskey para formularios
            ]);
    }
    
    //Resetea todos los estados de invitación a "Sin procesar"
    private function resetInvitationStatuses(): void {
            global $DB;
        
            try {
                $count = $DB->execute(
                    "UPDATE {usuarios_data_patroller} SET invitacion_status = 0 WHERE id_materia = ?", 
                    [$this->course->id]
                );
            
                error_log("PLUGIN INFO: Estados de invitación reseteados para curso {$this->course->id}");
            
            } catch (\Exception $e) {
                error_log("PLUGIN ERROR: Error al resetear estados de invitación: " . $e->getMessage());
                throw $e;
            }
    }
    
    //Obtiene lista de repositorios disponibles con espacio suficiente
    private function getAvailableRepositoriesWithCapacity(array $raw_repos, int $current_user_id, ?string $current_repo_name = null): array {
        global $DB;
        
        $max_capacity = $this->pluginpatroller->max_users_per_group;
        $available_options = [];
        
        foreach ($raw_repos as $repo_id => $repo_name) {
            // Excluir el repositorio actual del estudiante
            if ($current_repo_name && $repo_name === $current_repo_name) {
                continue;
            }
            
            // Contar estudiantes actuales en este repo (excluyendo al usuario que se est├í cambiando)
            $current_count = $DB->count_records_sql(
                "SELECT COUNT(*) 
                 FROM {usuarios_data_patroller} 
                 WHERE id_repo = ? 
                 AND id_materia = ? 
                 AND id_usuario != ?",
                [$repo_id, $this->course->id, $current_user_id]
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
                'text' => 'No hay otros repositorios con espacio disponible',
                'selected' => false
            ];
        }
        
        return $available_options;
    }

    //Maneja el cambio de repositorio para un estudiante
    private function handleRepositoryChange(int $user_id): void {
        global $DB;

        $new_repo_name = $this->getParam('new_repo_name');
        
        if (empty($new_repo_name)) {
            throw new \Exception('Debe seleccionar un repositorio');
        }
        
        // Validar que el repositorio existe y pertenece al curso
        $repo = $DB->get_record('repositorios_data_patroller', [
            'nombre_repo' => $new_repo_name,
            'id_materia' => $this->course->id
        ]);

        if (!$repo) {
            throw new \Exception('Repositorio inv├ílido o no pertenece a este curso');
        }

        // Verificar que el estudiante existe en la tabla
        $student = $DB->get_record('usuarios_data_patroller', [
            'id_usuario' => $user_id,
            'id_materia' => $this->course->id
        ]);

        if (!$student) {
            throw new \Exception('Estudiante no encontrado');
        }

        // Verificar capacidad del repositorio (excluyendo al usuario actual)
        $max_capacity = $this->pluginpatroller->max_users_per_group;
        $current_count = $DB->count_records_sql(
            "SELECT COUNT(*) 
             FROM {usuarios_data_patroller} 
             WHERE id_repo = ? 
             AND id_materia = ? 
             AND id_usuario != ?",
            [$repo->id, $this->course->id, $user_id]
        );

        if ($current_count >= $max_capacity) {
            throw new \Exception("El repositorio '{$new_repo_name}' ya alcanz su capacidad máxima ({$max_capacity} estudiantes)");
        }

        // Actualizar el repositorio y reiniciar estado de invitación
        $DB->execute(
            "UPDATE {usuarios_data_patroller} 
             SET id_repo = ?, 
                 invitacion_status = 0
             WHERE id_usuario = ? 
             AND id_materia = ?",
            [$repo->id, $user_id, $this->course->id]
        );
    }
    
    // Maneja peticiones AJAX específicas
    public function handleAjaxRequest(): void {
        error_log("DEBUG AJAX: handleAjaxRequest() iniciado");
        
        try {
            $action = $this->getParam('action');
            error_log("DEBUG AJAX: Action recibida: " . ($action ?: 'EMPTY'));
            
            switch ($action) {
                case 'sync_statuses':
                    $this->handleSyncStatusesAjax();
                    break;
                    
                case 'send_invitations':
                    $this->handleSendInvitationsAjax();
                    break;
                    
                case 'cancel_invitation':
                    $this->handleCancelInvitationAjax();
                    break;
                    
                case 'change_repository':
                    $this->handleChangeRepositoryAjax();
                    break;
                    
                default:
                    error_log("DEBUG AJAX: Acción no reconocida: $action");
                    $this->sendAjaxResponse([
                        'success' => false,
                        'message' => 'Acción no válida'
                    ]);
            }
        } catch (\Exception $e) {
            error_log("DEBUG AJAX: Error en handleAjaxRequest: " . $e->getMessage());
            $this->sendAjaxResponse([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            ]);
        }
    }
    
    //Maneja sincronizacion de estados via AJAX
    private function handleSyncStatusesAjax(): void {
        error_log("DEBUG AJAX: handleSyncStatusesAjax() iniciado");
        
        try {
            // Usar el servicio existente para sincronizar
            $this->initializeServices();
            $this->gitHubAccessService->syncInvitationStatuses($this->course->id);
            
            error_log("DEBUG AJAX: Enviando respuesta de sincronización exitosa");
            $this->sendAjaxResponse([
                'success' => true,
                'message' => 'Estados sincronizados correctamente',
                'students' => $this->getUpdatedStudentsData()
            ]);
        } catch (\Exception $e) {
            error_log("DEBUG AJAX: Error en sync: " . $e->getMessage());
            $this->sendAjaxResponse([
                'success' => false,
                'message' => 'Error al sincronizar: ' . $e->getMessage()
            ]);
        }
    }
    
    // Maneja envio de invitaciones via AJAX
    private function handleSendInvitationsAjax(): void {
        $repository_selected = $this->getParam('repository_selected');
        $force_resend = $this->getParam('force_resend') ? true : false;
        
        if (!$repository_selected) {
            $this->sendAjaxResponse([
                'success' => false,
                'message' => 'Debe seleccionar un repositorio'
            ]);
            return;
        }
        
        try {
            $this->initializeServices();
            $result = $this->gitHubAccessService->processInvitations($repository_selected, $force_resend);
            
            $this->sendAjaxResponse([
                'success' => true,
                'message' => $result,
                'students' => $this->getUpdatedStudentsData()
            ]);
        } catch (\Exception $e) {
            $this->sendAjaxResponse([
                'success' => false,
                'message' => 'Error al enviar invitaciones: ' . $e->getMessage()
            ]);
        }
    }
    
    //Maneja cancelacion de invitaciones via AJAX
    private function handleCancelInvitationAjax(): void {
        // Implementar l├│gica de cancelaci├│n
        $this->sendAjaxResponse([
            'success' => true,
            'message' => 'Invitación cancelada',
            'students' => $this->getUpdatedStudentsData()
        ]);
    }
    
    //Maneja cambio de repositorio via AJAX
    private function handleChangeRepositoryAjax(): void {
        $user_id = $this->getParam('change_repo_user_id');
        
        if (!$user_id) {
            $this->sendAjaxResponse([
                'success' => false,
                'message' => 'ID de usuario requerido'
            ]);
            return;
        }
        
        try {
            $this->handleRepositoryChange((int)$user_id);
            
            $this->sendAjaxResponse([
                'success' => true,
                'message' => 'Repositorio cambiado correctamente',
                'students' => $this->getUpdatedStudentsData()
            ]);
        } catch (\Exception $e) {
            $this->sendAjaxResponse([
                'success' => false,
                'message' => 'Error al cambiar repositorio: ' . $e->getMessage()
            ]);
        }
    }
    
    //Verifica si la peticion actual es AJAX
    private function isAjaxRequest(): bool {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        error_log("DEBUG AJAX: isAjaxRequest = " . ($isAjax ? 'true' : 'false'));
        error_log("DEBUG AJAX: HTTP_X_REQUESTED_WITH = " . ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? 'no definido'));
        
        return $isAjax;
    }
    
    // Envia respuesta AJAX en formato JSON
    private function sendAjaxResponse(array $data): void {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    //Obtiene datos actualizados de estudiantes para respuesta AJAX
    private function getUpdatedStudentsData(): array {
        $students_data = $this->gitHubAccessService->getStudentsWithGitHubData($this->cm->id);
        
        // Procesar datos igual que en execute()
        foreach ($students_data as &$student) {
            $student['show_change_form'] = false; // Sin formularios en AJAX
            $student['available_repos'] = []; // Sin opciones extra en AJAX
        }
        unset($student);
        
        return $students_data;
    }
}
