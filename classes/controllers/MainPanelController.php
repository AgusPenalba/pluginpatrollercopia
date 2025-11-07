<?php
namespace mod_pluginpatroller\controllers;

use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\service\StatisticsService;

use mod_pluginpatroller\model\UserModel;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;
use mod_pluginpatroller\helpers\UserHelper;
use mod_pluginpatroller\service\StudentGroupService;
use mod_pluginpatroller\service\RepositoryCreationService;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/weblib.php');

class MainPanelController extends AbstractController {
    
    private $studentGroupService;
    private $repoCreationService;
    private $statisticsService;

    /*Controla todo el flujo del panel principal
     * - Verifica permisos usando helper optimizado
     * - Carga estudiantes por grupos 
     * - Sincroniza base de datos
     * - Maneja creación de repositorios
     * - Prepara datos para mostrar */
    public function execute(): string {
        $this->requireTeacherPermissions();
        $this->initializeServices();

        try {
            $max_students_per_repo = $this->pluginpatroller->max_users_per_group;

            // Manejar eliminación ANTES de cargar datos para que el recálculo sea automático
            if ($this->getParam('delete_repo_id')) {
                $this->handleRepositoryDeletion();
            }

            $users_by_group = $this->studentGroupService->getStudentsByCourseGroup();
            $this->studentGroupService->syncStudentsToPatrollerTable($users_by_group);
            
            // DISABLED: Actualizar estados de invitaciones automáticamente
            // $this->updateInvitationStatuses();
            
            // Cargar users_by_repo inicial
            $users_by_repo = $this->repoCreationService->getReposWithNestedStudents();
            
            if ($this->getParam('create_repos_submit')) {
                $this->handleRepositoryCreation($users_by_repo);
                // RECARGAR datos después de crear repositorios para actualizar contadores
                $users_by_group = $this->studentGroupService->getStudentsByCourseGroup();
                $this->studentGroupService->syncStudentsToPatrollerTable($users_by_group);
                $users_by_repo = $this->repoCreationService->getReposWithNestedStudents();
                error_log("PLUGIN DEBUG: Datos recargados después de crear repositorios");
            }

            $data = $this->prepareTemplateData($users_by_group, $users_by_repo, $max_students_per_repo);
            return $this->render('main_panel', $data);

        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Renderiza sólo la vista de estadísticas para admin
     */
    public function executeStatistics(): string {
        $this->requireTeacherPermissions();
        $this->initializeServices();

        try {
            $max_students_per_repo = $this->pluginpatroller->max_users_per_group;
            $users_by_group = $this->studentGroupService->getStudentsByCourseGroup();
            $this->studentGroupService->syncStudentsToPatrollerTable($users_by_group);
            // DISABLED: $this->updateInvitationStatuses();
            $users_by_repo = $this->repoCreationService->getReposWithNestedStudents();

            $data = $this->prepareTemplateData($users_by_group, $users_by_repo, $max_students_per_repo);
            return $this->render('statistics', $data);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }
    
    /*Inicializa los servicios especializados*/
    private function initializeServices(): void {
        $this->studentGroupService = new StudentGroupService($this->context, $this->course);
        
        $owner = ConfigHelper::getGitHubOwner();
        $token = ConfigHelper::getGitHubToken();
        
        // Validar que esté configurado
        if (empty($token) || empty($owner)) {
            throw new \Exception('Plugin GitPatroller no configurado. Configure token y organización GitHub en: Administración del sitio → Plugins → Módulos de actividad → PatrollerPRF');
        }
        
        $githubAPI = new GitHubPatrollerAPI($owner, $token);
        $this->repoCreationService = new RepositoryCreationService($this->course, $githubAPI);
        $this->statisticsService = new StatisticsService();
    }
    
    /*Maneja la creación de repositorios con redirección*/
    private function handleRepositoryCreation(array $users_by_repo): void {
        $selected_groups = $_POST['selected_groups'] ?? [];
        $repos_to_add = $_POST['repos_to_add'] ?? [];
        
        $this->repoCreationService->processCreateRepos($selected_groups, $repos_to_add, $users_by_repo);
        
        // Agregar mensaje de éxito pero NO hacer redirect para que se recarguen los datos automáticamente
        $this->success_message = 'Repositorios creados exitosamente. Los estudiantes han sido asignados automáticamente.';
    }

    /*Maneja la eliminación de repositorios*/
    private function handleRepositoryDeletion(): void {
        global $DB;
        
        $repo_id = (int)$this->getParam('delete_repo_id');
        
        if ($repo_id <= 0) {
            throw new \Exception('ID de repositorio inválido');
        }
        
        // Verificar que el repositorio existe y pertenece al curso actual
        $repo = $DB->get_record('repositorios_data_patroller', [
            'id' => $repo_id,
            'id_materia' => $this->course->id
        ]);
        
        if (!$repo) {
            throw new \Exception('Repositorio no encontrado o no pertenece a este curso');
        }
        
        $repo_name = $repo->nombre_repo;
        
        try {
            // 1. Liberar estudiantes asignados (resetear id_repo a 0)
            $DB->execute("UPDATE {usuarios_data_patroller} 
                         SET id_repo = 0, invitacion_status = 0, usuario_github = ''
                         WHERE id_repo = ? AND id_materia = ?", 
                         [$repo_id, $this->course->id]);
            
            // 2. Eliminar el repositorio de GitHub PRIMERO
            $owner = ConfigHelper::getGitHubOwner();
            $token = ConfigHelper::getGitHubToken();
            
            if (!empty($owner) && !empty($token)) {
                $githubAPI = new GitHubPatrollerAPI($owner, $token);
                
                try {
                    $result = $githubAPI->deleteRepository($repo_name);
                    error_log("PLUGIN DEBUG: Repositorio '$repo_name' eliminado de GitHub exitosamente");
                } catch (\Exception $github_error) {
                    error_log("PLUGIN DEBUG: Error eliminando repositorio '$repo_name' de GitHub: " . $github_error->getMessage());
                    // Continuar con eliminación de BD aunque falle GitHub
                    // El usuario será informado del error parcial
                }
            } else {
                error_log("PLUGIN DEBUG: No se puede eliminar de GitHub - configuración faltante (owner/token)");
            }
            
            // 3. Eliminar el repositorio de la tabla
            $DB->delete_records('repositorios_data_patroller', ['id' => $repo_id]);
            
            error_log("PLUGIN DEBUG: Repositorio '$repo_name' (ID: $repo_id) eliminado exitosamente de BD");
            
            // Agregar mensaje de éxito pero NO hacer redirect para que se recarguen los datos automáticamente
            $this->success_message = "Repositorio '$repo_name' eliminado exitosamente de GitHub y base de datos. Los estudiantes han sido liberados.";
            
        } catch (\Exception $e) {
            error_log("PLUGIN DEBUG: Error eliminando repositorio ID $repo_id: " . $e->getMessage());
            throw new \Exception('Error al eliminar repositorio: ' . $e->getMessage());
        }
    }

    /*Organiza toda la información para el template usando servicios optimizados*/
    private function prepareTemplateData(array $users_by_group, array $users_by_repo, int $max_students_per_repo): array {
        global $COURSE, $USER;
        
        // Preparar datos del curso
        $parts = explode('-', $COURSE->shortname);
        $year = isset($parts[1]) ? $parts[1] : '';
        $semester = isset($parts[2]) ? $parts[2] : '';
        
        // Calcular total de pendientes
        $total_pending = $this->getTotalPendingStudents($users_by_group, $users_by_repo);
        
        // Preparar datos matriciales para alumnos pendientes (funcionalidad original)
        $pending_groups_data = $this->preparePendingGroupsData($users_by_group, $users_by_repo, $max_students_per_repo);
        
        // Preparar repositorios creados con estadísticas detalladas (funcionalidad original)
    $created_repos_data = $this->prepareCreatedReposData($users_by_repo);

    // Estadísticas por repositorio para comparativas en admin
        $repo_stats = $this->statisticsService->calculatePerRepo($users_by_repo);

        // Construir arrays para Chart.js combinando todos los estudiantes de todos los repos (centralizado)
        $chartArrays = $this->statisticsService->chartArraysFromRepos($users_by_repo);
        $chart_labels = $chartArrays['labels'] ?? [];
        $chart_commits = $chartArrays['commits'] ?? [];
        $chart_lines = $chartArrays['lines'] ?? [];
        $chart_lines_added = $chartArrays['lines_added'] ?? [];
        $chart_lines_deleted = $chartArrays['lines_deleted'] ?? [];
        $chart_lines_modified = $chartArrays['lines_modified'] ?? [];

        // Preparar datos por repositorio (repos == grupos de repos)
        $group_options = [];
        $first_group_key = null;
        $group_chart_map = [];
        $groupIndex = 0;
        foreach ($users_by_repo as $repo_key => $repo_info) {
            $groupIndex++;
            $students = $repo_info['students'] ?? [];
            if ($first_group_key === null) $first_group_key = $repo_key;
            $repo_name = $repo_info['nombre_repo'] ?? $repo_key;
            $label_text = 'Grupo ' . $groupIndex . ' — ' . $repo_name . ' (' . count($students) . ' alumnos)';
            $group_options[] = [ 'value' => $repo_key, 'text' => $label_text, 'repo_name' => $repo_name, 'group_label' => 'Grupo ' . $groupIndex ];

            // arrays por estudiantes del repo
            $arrays = $this->statisticsService->chartArraysFromStudents($students);
            $group_chart_map[$repo_key] = $arrays;
        }

        if ($first_group_key === null) {
            $first_group_key = '';
        }

        // Agregados por grupo (suma por repo)
        $groups_labels = [];
        $groups_commits = [];
        $groups_lines_added = [];
        $groups_lines_deleted = [];
        $groups_lines_modified = [];

        $groupIndex = 0;
        foreach ($users_by_repo as $repo_key => $repo_info) {
            $groupIndex++;
            $students = $repo_info['students'] ?? [];
            $groups_labels[] = 'Grupo ' . $groupIndex;

            $sum_commits = 0; $sum_added = 0; $sum_deleted = 0; $sum_modified = 0;
            foreach ($students as $s) {
                if (is_object($s)) {
                    $sum_commits += isset($s->cantidad_commits) ? (int)$s->cantidad_commits : (int)($s->commit_count ?? 0);
                    $sum_added += isset($s->lineas_agregadas) ? (int)$s->lineas_agregadas : (int)($s->lines_added ?? 0);
                    $sum_deleted += isset($s->lineas_eliminadas) ? (int)$s->lineas_eliminadas : (int)($s->lines_deleted ?? 0);
                    $sum_modified += isset($s->lineas_modificadas) ? (int)$s->lineas_modificadas : (int)($s->lines_modified ?? 0);
                } else {
                    $sum_commits += isset($s['cantidad_commits']) ? (int)$s['cantidad_commits'] : (int)($s['commit_count'] ?? 0);
                    $sum_added += isset($s['lineas_agregadas']) ? (int)$s['lineas_agregadas'] : (int)($s['lines_added'] ?? 0);
                    $sum_deleted += isset($s['lineas_eliminadas']) ? (int)$s['lineas_eliminadas'] : (int)($s['lines_deleted'] ?? 0);
                    $sum_modified += isset($s['lineas_modificadas']) ? (int)$s['lineas_modificadas'] : (int)($s['lines_modified'] ?? 0);
                }
            }
            $groups_commits[] = $sum_commits;
            $groups_lines_added[] = $sum_added;
            $groups_lines_deleted[] = $sum_deleted;
            $groups_lines_modified[] = $sum_modified;
        }

        // Debug: Log de datos del template
    // Debug traces removed

        return [
            // Datos para el page_header component
            'header_icon' => 'fas fa-code-branch',
            'header_title' => 'Gestión de Repositorios',
            'header_subtitle' => 'Administración de repositorios GitHub',
            'course_name' => htmlspecialchars($COURSE->fullname),
            'user_fullname' => htmlspecialchars($USER->firstname . ' ' . $USER->lastname),
            
            // Datos básicos del curso (tabla de cabecera original)
            'course_info' => [
                'shortname' => htmlspecialchars($COURSE->shortname),
                'year' => htmlspecialchars($year),
                'semester' => htmlspecialchars($semester),
                'total_pending' => $total_pending,
                'max_students_per_repo' => $max_students_per_repo
            ],
            
            // Datos para formulario matricial (funcionalidad original)
            'cm_id' => $this->cm->id,
            'sesskey' => $USER->sesskey ?? '',
            'has_pending_groups' => !empty($pending_groups_data),
            'course_letters' => $this->getCourseLetters($users_by_group),
            'sedes' => $pending_groups_data,
            
            // Repositorios creados (funcionalidad original)
            'has_created_repos' => !empty($created_repos_data),
            'created_repos' => $created_repos_data,
            // Estadísticas por repositorio para la vista admin
            'repo_stats' => $repo_stats,
            // Arrays JSON para Chart.js (admin comparativa)
            'chart_labels_json' => json_encode($chart_labels),
            'chart_commits_json' => json_encode($chart_commits),
            'chart_lines_json' => json_encode($chart_lines),
            'chart_lines_added_json' => json_encode($chart_lines_added),
            'chart_lines_deleted_json' => json_encode($chart_lines_deleted),
            'chart_lines_modified_json' => json_encode($chart_lines_modified),
            // datos por repo (grupos)
            'group_options' => $group_options,
            'first_group_key' => $first_group_key,
            'group_chart_map_json' => json_encode($group_chart_map),
            // agregados por repo
            'groups_labels_json' => json_encode($groups_labels),
            'groups_commits_json' => json_encode($groups_commits),
            'groups_lines_added_json' => json_encode($groups_lines_added),
            'groups_lines_deleted_json' => json_encode($groups_lines_deleted),
            'groups_lines_modified_json' => json_encode($groups_lines_modified),
        ];
    }
    
    /*Obtiene las letras de curso únicas*/
    private function getCourseLetters(array $users_by_group): array {
        $letters = [];
        foreach ($users_by_group as $group_key => $students_in_course) {
            $parts = explode('-', $group_key);
            if (count($parts) >= 2) {
                $letters[] = $parts[1]; // La letra del curso
            }
        }
        return array_unique($letters);
    }
    
    /*Prepara datos matriciales de alumnos pendientes por sede y curso*/
    private function preparePendingGroupsData(array $users_by_group, array $users_by_repo, int $max_students_per_repo): array {
        // 1. Obtener sedes y letras únicas
        $sedes = [];
        $course_letters = [];
        foreach ($users_by_group as $group_key => $students_in_course) {
            $parts = explode('-', $group_key);
            if (count($parts) >= 2) {
                $sedes[] = $parts[0]; // Sede
                $course_letters[] = $parts[1]; // Letra del curso
            }
        }
        $sedes = array_unique($sedes);
        sort($sedes);
        $course_letters = array_unique($course_letters);
        sort($course_letters);

        // 2. Construir matriz de datos
        $sedes_data = [];
        foreach ($sedes as $sede) {
            $courses_data = [];
            foreach ($course_letters as $letter) {
                $group_key = $sede . '-' . $letter;
                $needs_repos = $this->groupNeedsMoreRepositories($users_by_group, $users_by_repo, $group_key, $max_students_per_repo);
                
                $course_data = [
                    'group_key' => $group_key,
                    'has_pending' => $needs_repos,
                    'pending_count' => 0 // Ya no mostramos el conteo confuso
                ];
                
                if ($needs_repos) {
                    // Calcular cuántos repositorios realmente se necesitan crear
                    [$sede_check, $curso_check] = explode('-', $group_key);
                    $existing_repos_for_group = $this->countExistingReposForGroup($users_by_repo, $sede_check, $curso_check);
                    $total_students_in_group = count($users_by_group[$group_key]['students'] ?? []);
                    $total_repos_needed = (int)ceil($total_students_in_group / $max_students_per_repo);
                    
                    $repos_to_create = max(0, $total_repos_needed - $existing_repos_for_group);
                    
                    // Debug temporal
                    // debug info removed
                    
                    if ($repos_to_create > 0) {
                        $repo_options = [];
                        for ($i = 1; $i <= $repos_to_create; $i++) {
                            $repo_options[] = [
                                'value' => $i,
                                'text' => $i . ' Repositorio' . ($i > 1 ? 's' : '') . ' adicional' . ($i > 1 ? 'es' : '')
                            ];
                        }
                        $course_data['repo_options'] = $repo_options;
                    } else {
                        // Si no necesita crear repos, no tiene pendientes
                        $course_data['has_pending'] = false;
                        $course_data['pending_count'] = 0;
                        // debug info removed
                    }
                }
                
                $courses_data[] = $course_data;
            }
            
            $sedes_data[] = [
                'sede_name' => $sede,
                'courses' => $courses_data
            ];
        }
        
        return $sedes_data;
    }
    
    /*Prepara datos de repositorios creados con estadísticas*/
    private function prepareCreatedReposData(array $users_by_repo): array {
        global $USER;
        $created_repos = [];
        foreach ($users_by_repo as $repo_id => $repo) {
            $count_by_status = $this->countStudentsByStatus($repo['students']);
            // Construir repository_url para cada repositorio (si es posible)
            $owner = ConfigHelper::getGitHubOwner();
            $repo_name = $repo['nombre_repo'] ?? '';
            $repository_url = '';
            if (!empty($repo_name)) {
                if (strpos($repo_name, '/') !== false) {
                    $repository_url = 'https://github.com/' . rawurlencode($repo_name);
                } elseif (!empty($owner)) {
                    $repository_url = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo_name);
                }
            }

            $created_repos[] = [
                'repo_id' => $repo_id,
                'sede' => htmlspecialchars($repo['sede']),
                'curso' => htmlspecialchars($repo['curso']),
                'nro' => htmlspecialchars($repo['nro']),
                'nombre_repo' => htmlspecialchars($repo['nombre_repo']),
                'total_members' => count($repo['students']),
                'accepted' => $count_by_status['ACCEPTED'] ?? 0,
                'pending' => $count_by_status['INVITATION_SENT'] ?? 0, // Solo contar los que tienen invitación enviada
                'not_found' => 0, // Este estado no se usa en nuestro sistema
                'missing_username' => 0, // Este estado no se usa en nuestro sistema  
                'unprocessed' => $count_by_status['UNPROCESSED'] ?? 0,
                'error' => $count_by_status['ERROR'] ?? 0,
                'repository_url' => $repository_url,
                'sesskey' => $USER->sesskey ?? '',
                'cm_id' => $this->cm->id
            ];
        }
        return $created_repos;
    }
    
    /*Cuenta estudiantes por estado de registro en GitHub*/
    private function countStudentsByStatus(array $students): array {
        $counts = [];
        foreach ($students as $student) {
            // Usar invitacion_status que es el campo real en la BD
            $numeric_status = $student->invitacion_status ?? 0;
            
            // debug trace removed
            
            // Mapear estados numéricos a texto para compatibilidad con template
            $status = match((int)$numeric_status) {
                0 => 'UNPROCESSED',
                1 => 'INVITATION_SENT', 
                2 => 'ERROR',
                3 => 'ACCEPTED',
                default => 'UNPROCESSED'
            };
            
            // debug trace removed
            
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        
    // debug trace removed
        return $counts;
    }
    
    /*Obtiene total de grupos que necesitan más repositorios (no estudiantes individuales)*/
    private function getTotalPendingStudents(array $users_by_group, array $users_by_repo): int {
        $total_repos_needed = 0;
        $total_existing_repos = 0;
        $max_students_per_repo = $this->pluginpatroller->max_users_per_group;
        
        error_log("PLUGIN DEBUG: === CÁLCULO DE REPOSITORIOS PENDIENTES ===");
        error_log("PLUGIN DEBUG: Max estudiantes por repo: $max_students_per_repo");
        
        foreach ($users_by_group as $group_key => $users_in_course) {
            [$sede, $curso] = explode('-', $group_key);
            
            $total_students_in_group = count($users_in_course['students']);
            $repos_needed_for_group = (int)ceil($total_students_in_group / $max_students_per_repo);
            $existing_repos_for_group = $this->countExistingReposForGroup($users_by_repo, $sede, $curso);
            
            error_log("PLUGIN DEBUG: Grupo $group_key - Estudiantes: $total_students_in_group, Repos necesarios: $repos_needed_for_group, Repos existentes: $existing_repos_for_group");
            
            $total_repos_needed += $repos_needed_for_group;
            $total_existing_repos += $existing_repos_for_group;
        }
        
        $repos_pending = max(0, $total_repos_needed - $total_existing_repos);
        error_log("PLUGIN DEBUG: Total repos necesarios: $total_repos_needed, Total repos existentes: $total_existing_repos, Repos pendientes: $repos_pending");
        
        return $repos_pending;
    }
    
    /*Verifica si un grupo necesita más repositorios*/
    private function groupNeedsMoreRepositories(array $users_by_group, array $users_by_repo, string $group_key, int $max_students_per_repo): bool {
        if (!isset($users_by_group[$group_key])) {
            return false;
        }
        
        [$sede, $curso] = explode('-', $group_key);
        
        // Contar estudiantes sin asignar a repositorios en este grupo
        $unassigned_students = $this->countUnassignedStudentsForGroup($users_by_group[$group_key]['students']);
        
        // Contar capacidad disponible en repositorios existentes del grupo
        $available_capacity = $this->countAvailableCapacityForGroup($users_by_repo, $sede, $curso, $max_students_per_repo);
        
        // Necesitamos más repositorios si hay estudiantes sin asignar que no caben en la capacidad disponible
        $needs_more = $unassigned_students > $available_capacity;
        
        error_log("PLUGIN DEBUG: Grupo $group_key - Estudiantes sin asignar: $unassigned_students, Capacidad disponible: $available_capacity, Necesita más repos: " . ($needs_more ? 'SÍ' : 'NO'));
        
        return $needs_more;
    }

    /*Cuenta repositorios existentes para un grupo específico*/
    private function countExistingReposForGroup(array $users_by_repo, string $sede, string $curso): int {
        $count = 0;
        foreach ($users_by_repo as $repo) {
            if ($repo['sede'] === $sede && $repo['curso'] === $curso) {
                $count++;
            }
        }
        return $count;
    }
    
    /*Cuenta estudiantes sin asignar a repositorios en un grupo*/
    private function countUnassignedStudentsForGroup(array $students): int {
        global $DB;
        
        $count = 0;
        foreach ($students as $student) {
            // Obtener el id_repo de la tabla usuarios_data_patroller
            $user_id = $student['id_usuario'] ?? null;
            
            if (!$user_id) {
                error_log("PLUGIN DEBUG: Estudiante sin id_usuario - " . var_export($student, true));
                continue;
            }
            
            $patroller_record = $DB->get_record('usuarios_data_patroller', [
                'id_usuario' => $user_id,
                'id_materia' => $this->course->id
            ]);
            
            $repo_id = $patroller_record ? $patroller_record->id_repo : null;
            $is_unassigned = empty($repo_id) || $repo_id == 0 || is_null($repo_id);
            
            if ($is_unassigned) {
                $count++;
                error_log("PLUGIN DEBUG: Estudiante sin asignar - ID: {$user_id}, repo_id: " . var_export($repo_id, true));
            } else {
                error_log("PLUGIN DEBUG: Estudiante asignado - ID: {$user_id}, repo_id: $repo_id");
            }
        }
        return $count;
    }
    
    /*Cuenta la capacidad disponible en repositorios existentes de un grupo*/
    private function countAvailableCapacityForGroup(array $users_by_repo, string $sede, string $curso, int $max_students_per_repo): int {
        $total_capacity = 0;
        
        foreach ($users_by_repo as $repo) {
            if ($repo['sede'] === $sede && $repo['curso'] === $curso) {
                $current_members = count($repo['students']);
                $available_in_this_repo = max(0, $max_students_per_repo - $current_members);
                $total_capacity += $available_in_this_repo;
            }
        }
        
        return $total_capacity;
    }
    
    /**
     * Actualiza automáticamente los estados de invitaciones verificando con GitHub API
     * Solo verifica usuarios con estado "invitación enviada" (1) para ver si ya fueron aceptadas
     */
    private function updateInvitationStatuses(): void {
        try {
            // debug trace removed
            
            global $DB;
            
            // Debug: mostrar TODOS los usuarios y sus estados actuales (usando JOIN)
            $debug_sql = "SELECT u.id, u.nombre_usuario, u.usuario_github, u.invitacion_status, r.nombre_repo
                          FROM {usuarios_data_patroller} u
                          LEFT JOIN {repositorios_data_patroller} r ON u.id_repo = r.id";
            $all_users = $DB->get_records_sql($debug_sql);
            // debug traces removed
            
            // Obtener usuarios con invitación enviada (status 1) que podrían haber sido aceptadas
            // Necesitamos hacer JOIN para obtener el nombre del repositorio
            $sql = "SELECT u.id, u.usuario_github, r.nombre_repo
                    FROM {usuarios_data_patroller} u
                    JOIN {repositorios_data_patroller} r ON u.id_repo = r.id
                    WHERE u.invitacion_status = ?";
            
            $users_with_pending_invitations = $DB->get_records_sql($sql, [1]);
            
            if (empty($users_with_pending_invitations)) {
                return;
            }
            
            // Debug: mostrar qué usuarios se van a verificar
            // debug traces removed
            
            $api = new \mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI(
                \mod_pluginpatroller\helpers\ConfigHelper::getGitHubOwner(),
                \mod_pluginpatroller\helpers\ConfigHelper::getGitHubToken()
            );
            
            $updates_made = 0;
            
            foreach ($users_with_pending_invitations as $user) {
                if (empty($user->usuario_github) || empty($user->nombre_repo)) {
                    continue;
                }
                
                try {
                    // Verificar si el usuario ya es colaborador activo del repositorio
                    $collaborators = $api->listCollaborators($user->nombre_repo);
                    
                    $is_collaborator = false;
                    
                    foreach ($collaborators as $collaborator) {
                        if (strtolower($collaborator['login']) === strtolower($user->usuario_github)) {
                            $is_collaborator = true;
                            break;
                        }
                    }
                    
                    if ($is_collaborator) {
                        // Usuario aceptó la invitación - actualizar a status 3 (Aceptado)
                        $DB->update_record('usuarios_data_patroller', (object)[
                            'id' => $user->id,
                            'invitacion_status' => 3
                        ]);
                        $updates_made++;
                    }
                    
                } catch (\Exception $e) {
                    // ignore individual errors during verification
                }
            }
            
            
        } catch (\Exception $e) {
            // ignore errors during automatic invitation status update
        }
    }

}