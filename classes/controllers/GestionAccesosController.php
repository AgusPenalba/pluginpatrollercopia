<?php
namespace mod_pluginpatroller\controllers;

use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\service\GitHubAccessService;
use mod_pluginpatroller\service\StudentManagementService;
use mod_pluginpatroller\service\TemplateDataService;
use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\helpers\FilterHelper;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;

defined('MOODLE_INTERNAL') || die();

/* Maneja invitaciones de GitHub, asignación de repositorios y estado de estudiantes */
class GestionAccesosController extends AbstractController {
    
    private $gitHubAccessService;
    private $studentManagementService;
    private $templateDataService;
    
    /*Orquesta todo el flujo de gestión de accesos usando servicios especializados*/
        public function execute(): string {
            $this->requireTeacherPermissions();
            $this->initializeServices();
        
            try {
                $success_message = '';

                // Procesar acción de cambio de repositorio
                $action = $this->getParam('action');
                if ($action === 'show_change_repo_form') {
                    return $this->showChangeRepositoryForm();
                }

                // Procesar cambio de repositorio confirmado
                $change_repo_confirmed = $this->getParam('change_repo_confirmed');
                if ($change_repo_confirmed) {
                    $this->processRepositoryChange();
                    $this->redirect($this->getPluginUrl(['tab' => 'tab2']), 'Repositorio cambiado exitosamente. Estado de invitación rehabilitado.', 2);
                }

                // Procesar test de API
                $test_api = $this->getParam('test_api');
                if ($test_api) {
                    try {
                        $test_result = $this->testGitHubAPI();
                        $success_message = "🧪 TEST API RESULTADO:<br>" . $test_result;
                    } catch (\Exception $e) {
                        $success_message = "❌ ERROR EN TEST API: " . $e->getMessage();
                    }
                }
            
                // Procesar invitaciones SOLO si se presionó el botón (campo send_invitations presente)
                $repository_selected = $this->getParam('repository_selected');
                $is_manual_send = $this->getParam('send_invitations');
                
                if ($repository_selected && $is_manual_send) {
                    try {
                        $force_resend = $this->getParam('force_resend') ? true : false;
                        $result = $this->gitHubAccessService->processInvitations($repository_selected, $force_resend);
                        
                        // REDIRIGIR para evitar reenvío automático al refrescar la página
                        $this->redirect($this->getPluginUrl(['tab' => 'tab2']), "✅ " . $result, 3);
                        
                    } catch (\Exception $e) {
                        $error_message = "❌ ERROR: " . $e->getMessage();
                        $debug_info = $this->getDebugInfo($repository_selected);
                        $success_message = "🔍 PROCESANDO REPOSITORIO: '$repository_selected'<br>" . $debug_info . "<br><br>" . $error_message;
                    }
                }

                // Procesar reset de invitaciones
                $reset_invitations = $this->getParam('reset_invitations');
                if ($reset_invitations) {
                    $this->resetInvitationStatuses();
                    $this->redirect($this->getPluginUrl(['tab' => 'tab2']), 'Estados de invitación reseteados exitosamente.', 2);
                }

                // Procesar envío de invitación individual
                $send_single_invitation = $this->getParam('send_single_invitation');
                if ($send_single_invitation) {
                    try {
                        $result = $this->gitHubAccessService->sendSingleInvitation($send_single_invitation);
                        if ($result['success']) {
                            $this->redirect($this->getPluginUrl(['tab' => 'tab2']), "✅ " . $result['message'], 3);
                        } else {
                            $success_message = "❌ " . $result['message'];
                        }
                    } catch (\Exception $e) {
                        $success_message = "❌ Error al enviar invitación: " . $e->getMessage();
                    }
                }

                // Procesar cancelación de invitación
                $cancel_invitation = $this->getParam('cancel_invitation');
                if ($cancel_invitation) {
                    try {
                        $result = $this->gitHubAccessService->cancelInvitation($cancel_invitation);
                        if ($result['success']) {
                            $success_message = "✅ " . $result['message'];
                        } else {
                            $success_message = "❌ " . $result['message'];
                        }
                    } catch (\Exception $e) {
                        $success_message = "❌ Error al cancelar invitación: " . $e->getMessage();
                    }
                }

                // Procesar cambios si se enviaron
                $guardar_cambios = $this->getParam('guardar_cambios');
                if ($guardar_cambios) {
                    $this->processSaveChanges();
                    $this->redirect($this->getPluginUrl(['tab' => 'tab2']), 'Cambios guardados exitosamente.', 2);
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
    
        /*Inicializa servicios especializados*/
        private function initializeServices(): void {
            $this->gitHubAccessService = new GitHubAccessService($this->course);
            $this->studentManagementService = new StudentManagementService($this->course);
            $this->templateDataService = new TemplateDataService($this->course, $this->cm);
        }
    
        /*Procesa cambios usando el servicio especializado*/
        private function processSaveChanges(): void {
            $github_changes = $_POST['github'] ?? [];
            $repo_changes = $_POST['repositorio'] ?? [];
        
            $this->gitHubAccessService->processSaveChanges($github_changes, $repo_changes);
        }
    
        /*Organiza toda la información para el template usando servicios*/
        private function prepareTemplateData(string $success_message = ''): array {
            // Configurar datos para filtros (SIN HTML)
            $filter_config = [
                'show_name' => true,
                'show_sede' => true,
                'show_curso' => true,
                'show_repo' => false
            ];
            $filters_data = FilterHelper::getFiltersData($this->course->id, $filter_config);
        
            // Obtener repositorios disponibles
            $repositories = RepositoryModel::getAllByCourseId($this->course->id);
        
            // Convertir formato [id => nombre] a [nombre => nombre] para el select
            $repositories_for_select = [];
            foreach ($repositories as $repo_id => $repo_name) {
                $repositories_for_select[$repo_name] = $repo_name;
            }
        
            $repository_options = array_merge(['All' => 'Todos los Repositorios'], $repositories_for_select);
        
            // Obtener estudiantes usando el servicio
            $students_data = $this->gitHubAccessService->getStudentsWithGitHubData($this->cm->id);
            $has_updatable_students = $this->gitHubAccessService->hasUpdatableStudents($students_data);

            return [
                // Header del template
                'header_icon' => 'fas fa-key',
                'header_title' => 'Gestión de Accesos GitHub',
                'header_subtitle' => 'Invitaciones y permisos de repositorios',
            
                // IDs necesarios para formularios
                'cm_id' => $this->cm->id,
            
                // Mensajes y estado
                'success_message' => $success_message,
                'has_success' => !empty($success_message),
            
                // Sección de invitaciones
                'repository_options' => $this->formatSelectOptions($repository_options),
            
                // Datos puros para filtros (SIN HTML)
                'filters_config' => $filters_data,
                'table_id' => 'studentsTable',
                'students' => $students_data,
                'has_students' => !empty($students_data),
                'has_updatable_students' => $has_updatable_students,
            
                // Estados para template
                'show_save_button' => $has_updatable_students
            ];
        }
    
        /**
         * Obtiene información de debug para mostrar en pantalla
         */
        private function getDebugInfo(string $repository_selected): string {
            global $DB;
        
            $debug = [];
            $debug[] = "📋 <strong>Repositorio seleccionado:</strong> '$repository_selected'";
        
            // Verificar repositorios disponibles
            $repositories_data = \mod_pluginpatroller\model\RepositoryModel::getAllByCourseId($this->course->id);
            $repositories = array_values($repositories_data); // Solo los nombres
            $debug[] = "📦 <strong>Repositorios disponibles:</strong> " . count($repositories) . " (" . implode(', ', $repositories) . ")";
        
            // DIAGNÓSTICO ESPECÍFICO para test-C-YA-B-1
            if ($repository_selected === 'test-C-YA-B-1' || in_array('test-C-YA-B-1', $repositories)) {
                $debug[] = "<br>🔍 <strong>DIAGNÓSTICO ESPECÍFICO PARA test-C-YA-B-1:</strong>";
            
                // Buscar por nombre exacto
                $repo_record = $DB->get_record('repositorios_data_patroller', [
                    'nombre_repo' => 'test-C-YA-B-1',
                    'id_materia' => $this->course->id
                ]);
            
                if ($repo_record) {
                    $debug[] = "✅ Repositorio encontrado en BD: ID={$repo_record->id}";
                
                    // Buscar estudiantes asignados
                    $students = $DB->get_records('usuarios_data_patroller', [
                        'id_repo' => $repo_record->id,
                        'id_materia' => $this->course->id
                    ]);
                
                    $debug[] = "👥 Estudiantes asignados: " . count($students);
                
                    foreach ($students as $student) {
                        $user_data = $DB->get_record('user', ['id' => $student->id_usuario]);
                        $debug[] = "- Estudiante ID: {$student->id}, Usuario: {$user_data->firstname} {$user_data->lastname}, GitHub: '{$student->usuario_github}', Estado: {$student->invitacion_status}";
                    }
                } else {
                    $debug[] = "❌ test-C-YA-B-1 NO encontrado en tabla repositorios_data_patroller";
                
                    // Mostrar todos los repositorios en la BD para comparar
                    $all_repos = $DB->get_records('repositorios_data_patroller', ['id_materia' => $this->course->id]);
                    $debug[] = "📋 Todos los repositorios en BD:";
                    foreach ($all_repos as $repo) {
                        $debug[] = "- ID: {$repo->id}, Nombre: '{$repo->nombre_repo}'";
                    }
                }
            }
        
            // Verificar si el repositorio existe
            if ($repository_selected !== 'All' && !isset($repositories[$repository_selected])) {
                $debug[] = "❌ <strong>ERROR:</strong> Repositorio '$repository_selected' no encontrado";
                return implode('<br>', $debug);
            }
        
            // Información general para otros repositorios
            if ($repository_selected !== 'test-C-YA-B-1') {
                if ($repository_selected === 'All') {
                    $repo_names = array_values($repositories);
                    $debug[] = "🎯 <strong>Procesando:</strong> TODOS los repositorios";
                } else {
                    $repo_names = [$repositories[$repository_selected]];
                    $debug[] = "🎯 <strong>Procesando:</strong> " . $repositories[$repository_selected];
                }
            
                // Verificar estudiantes para cada repositorio
                foreach ($repo_names as $repo_name) {
                    $repo_record = $DB->get_record('repositorios_data_patroller', [
                        'nombre_repo' => $repo_name,
                        'id_materia' => $this->course->id
                    ]);
                
                    if (!$repo_record) {
                        $debug[] = "❌ <strong>Repositorio '$repo_name':</strong> No encontrado en BD";
                        continue;
                    }
                
                    $students = $DB->get_records('usuarios_data_patroller', [
                        'id_repo' => $repo_record->id,
                        'id_materia' => $this->course->id
                    ]);
                
                    $debug[] = "👥 <strong>Repositorio '$repo_name':</strong> " . count($students) . " estudiantes asignados";
                
                    $with_github = 0;
                    foreach ($students as $student) {
                        if (!empty($student->usuario_github)) {
                            $with_github++;
                        }
                    }
                
                    $debug[] = "✅ <strong>Con GitHub username:</strong> $with_github de " . count($students);
                }
            }
        
            return implode('<br>', $debug);
        }
    
        /**
         * Resetea todos los estados de invitación a "Sin procesar"
         */
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
    
        /**
         * Test básico de conectividad y configuración de la API de GitHub
         */
        private function testGitHubAPI(): string {
            $results = [];
        
            try {
                // Test 1: Verificar configuración
                $owner = ConfigHelper::getGitHubOwner();
                $token = ConfigHelper::getGitHubToken();
            
                $results[] = "📋 <strong>Configuración:</strong>";
                $results[] = "- Owner: " . ($owner ? "✅ Configurado ($owner)" : "❌ No configurado");
                $results[] = "- Token: " . ($token ? "✅ Configurado (" . substr($token, 0, 10) . "...)" : "❌ No configurado");
            
                if (!$owner || !$token) {
                    $results[] = "❌ <strong>Error:</strong> Configuración incompleta";
                    return implode('<br>', $results);
                }
            // Test 2: Verificar conectividad básica
            $results[] = "<br>🌐 <strong>Test de Conectividad:</strong>";
            
            // Test básico de conectividad GitHub
            try {
                $githubAPI = new GitHubPatrollerAPI($owner, $token);
                $test_user = $githubAPI->userExists('github'); // GitHub siempre existe
                $results[] = "✅ Conectividad GitHub: OK";
            } catch (\Exception $e) {
                $results[] = "❌ Error de conectividad: " . $e->getMessage();
            }
            
            // Test 3: Listar repositorios del curso
            $results[] = "<br>📁 <strong>Repositorios del curso:</strong>";
            $repositories = \mod_pluginpatroller\model\RepositoryModel::getAllByCourseId($this->course->id);
            
            if (empty($repositories)) {
                $results[] = "⚠️ No hay repositorios configurados para este curso";
            } else {
                $results[] = "- Total: " . count($repositories) . " repositorios";
                foreach ($repositories as $id => $name) {
                    $results[] = "  • $name (ID: $id)";
                }
            }
            
            // Test 4: Estudiantes con GitHub username
            $results[] = "<br>👥 <strong>Estudiantes:</strong>";
            global $DB;
            $students = $DB->get_records('usuarios_data_patroller', ['id_materia' => $this->course->id]);
            $with_github = array_filter($students, fn($s) => !empty($s->usuario_github));
            
            $results[] = "- Total estudiantes: " . count($students);
            $results[] = "- Con GitHub username: " . count($with_github);
            
            if (count($with_github) > 0) {
                $results[] = "- Ejemplos:";
                $sample = array_slice($with_github, 0, 3);
                foreach ($sample as $student) {
                    $status_text = match($student->invitacion_status) {
                        0 => "Sin procesar",
                        1 => "Invitación enviada", 
                        2 => "Error",
                        3 => "Aceptado",
                        default => "Estado $student->invitacion_status"
                    };
                    $results[] = "  • {$student->usuario_github} - $status_text";
                }
            }
            
            // Test 5: Verificar invitaciones reales en GitHub
            $results[] = "<br>🔍 <strong>Verificación de invitaciones en GitHub:</strong>";
            
            if (!empty($repositories) && count($with_github) > 0) {
                // Tomar el primer repositorio y estudiante para verificar
                $first_repo = array_values($repositories)[0];
                $first_student = array_values($with_github)[0];
                
                $results[] = "- Verificando repo: '$first_repo'";
                $results[] = "- Usuario: '{$first_student->usuario_github}'";
                
                try {
                    // Crear instancia de API GitHub y listar invitaciones pendientes
                    $githubAPI = new GitHubPatrollerAPI($owner, $token);
                    $pending = $githubAPI->listPendingInvitations($first_repo);
                    $results[] = "- Invitaciones pendientes: " . count($pending);
                        
                    if (!empty($pending)) {
                        foreach ($pending as $inv) {
                            $invitee = $inv['invitee']['login'] ?? 'unknown';
                            $results[] = "  • $invitee (ID: {$inv['id']})";
                        }
                    }
                    
                    // Listar colaboradores actuales
                    $collaborators = $githubAPI->listCollaborators($first_repo);
                    $results[] = "- Colaboradores actuales: " . count($collaborators);
                    
                    $student_is_collaborator = false;
                    foreach ($collaborators as $collab) {
                        if ($collab['login'] === $first_student->usuario_github) {
                            $student_is_collaborator = true;
                            $results[] = "  ✅ {$first_student->usuario_github} ya es colaborador";
                            break;
                        }
                    }
                    
                    if (!$student_is_collaborator) {
                        $student_has_invitation = false;
                        foreach ($pending as $inv) {
                            if ($inv['invitee']['login'] === $first_student->usuario_github) {
                                $student_has_invitation = true;
                                $results[] = "  📩 {$first_student->usuario_github} tiene invitación pendiente (ID: {$inv['id']})";
                                break;
                            }
                        }
                        
                        if (!$student_has_invitation) {
                            $results[] = "  ⚠️ {$first_student->usuario_github} no tiene invitación pendiente ni es colaborador";
                        }
                    }
                    
                } catch (\Exception $verify_error) {
                    $results[] = "❌ Error verificando invitaciones: " . $verify_error->getMessage();
                }
            } else {
                $results[] = "⚠️ No hay repositorios o estudiantes para verificar";
            }

            $results[] = "<br>✅ <strong>Test completado exitosamente</strong>";
            
        } catch (\Exception $e) {
            $results[] = "❌ <strong>Error en test:</strong> " . $e->getMessage();
        }
        
        return implode('<br>', $results);
    }

    /**
     * Muestra el formulario para cambiar repositorio de un estudiante
     */
    private function showChangeRepositoryForm(): string {
        $student_id = $this->getParam('student_id');
        
        if (!$student_id) {
            throw new \Exception('ID de estudiante requerido');
        }

        // Obtener información del estudiante
        global $DB;
        $student = $DB->get_record('usuarios_data_patroller', 
            ['id_usuario' => $student_id, 'id_materia' => $this->course->id]);
        
        if (!$student) {
            throw new \Exception('Estudiante no encontrado');
        }

        // Obtener todos los repositorios disponibles
        $repositories = RepositoryModel::getAllByCourseId($this->course->id);
        $repository_options = [];
        foreach ($repositories as $repo_id => $repo_name) {
            $repository_options[] = [
                'value' => $repo_id,
                'name' => $repo_name,
                'selected' => ($repo_id == $student->id_repo)
            ];
        }

        // Obtener información del usuario de Moodle
        $user = $DB->get_record('user', ['id' => $student_id]);
        $student_name = $user ? $user->firstname . ' ' . $user->lastname : 'Usuario desconocido';

        $data = [
            'header_icon' => 'fas fa-exchange-alt',
            'header_title' => 'Cambiar Repositorio',
            'header_subtitle' => 'Cambiar asignación de repositorio y rehabilitar invitación',
            'cm_id' => $this->cm->id,
            'student_id' => $student_id,
            'student_name' => $student_name,
            'current_repo' => $student->repositorio_asignado ?? 'Sin repositorio',
            'repository_options' => $repository_options,
            'has_repositories' => !empty($repository_options)
        ];

        return $this->render('change_repository_form', $data);
    }

    /**
     * Procesa el cambio de repositorio y rehabilita la invitación
     */
    private function processRepositoryChange(): void {
        $student_id = $this->getParam('student_id');
        $new_repo_id = $this->getParam('new_repository_id');
        
        if (!$student_id || !$new_repo_id) {
            throw new \Exception('ID de estudiante y repositorio requeridos');
        }

        global $DB;
        
        // Obtener el nuevo repositorio
        $new_repo = $DB->get_record('repositorios_data_patroller', ['id' => $new_repo_id]);
        if (!$new_repo) {
            throw new \Exception('Repositorio no encontrado');
        }

        // Actualizar el repositorio del estudiante
        $DB->execute("UPDATE {usuarios_data_patroller} 
                     SET id_repo = ?, repositorio_asignado = ?, estado_invitacion = 'pendiente'
                     WHERE id_usuario = ? AND id_materia = ?", 
                     [$new_repo_id, $new_repo->repositorio, $student_id, $this->course->id]);

        // Log para seguimiento
        error_log("CAMBIO REPOSITORIO: Usuario $student_id cambiado a repositorio {$new_repo->repositorio} (ID: $new_repo_id). Estado rehabilitado a 'pendiente'.");
    }
}
