<?php
namespace mod_pluginpatroller\service\view;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\helpers\UserHelper;
use stdClass;

/**
 * Servicio que maneja la lógica de negocio para la vista de estudiantes
 * Proporciona datos tanto para estudiantes (compañeros) como profesores (usuarios no registrados)
 */
class StudentViewService {
    private object $course;
    private object $context;
    private int $userId;
    
    public function __construct(object $course, object $context, int $userId) {
        $this->course = $course;
        $this->context = $context;
        $this->userId = $userId;
    }
    
    /**
     * Obtiene datos completos para la vista de estudiante (compañeros de su sede/curso)
     * @param object $currentUser Usuario actual
     * @return array Datos organizados para el template
     */
    public function getStudentViewData(object $currentUser): array {
        // Obtener datos académicos del usuario actual
        $current_user_enriched = UserHelper::getEnrichedUser($this->course, $this->userId);
        $my_sede = $current_user_enriched->sede ?? '';
        $my_curso = $current_user_enriched->curso ?? '';
        
        // Obtener repositorios disponibles y compañeros
        $available_repos = $this->getAvailableRepositories($my_sede, $my_curso);
        $classmates_data = $this->getClassmatesData($my_sede, $my_curso, $available_repos);
        
        return [
            'my_sede_curso' => UserHelper::getAcademicLabel($my_sede, $my_curso),
            'current_user_id' => $this->userId,
            'classmates' => $classmates_data,
            'has_classmates' => !empty($classmates_data),
            'has_available_repos' => !empty($available_repos),
            'can_edit' => $this->canStudentEdit($classmates_data)
        ];
    }
    
    /**
     * Obtiene datos de estudiantes no registrados en el sistema
     * @return array Lista de estudiantes matriculados pero no registrados
     */
    public function getUnregisteredStudentsData(): array {
        global $DB;
    
    $enrolled_users = get_enrolled_users($this->context);
    $unregistered = [];
    
    foreach ($enrolled_users as $user) {
        // Solo estudiantes
        if (!user_has_role_assignment($user->id, 5, $this->context->id)) { // ROLE_STUDENT_ID = 5
            continue;
        }
        
        // Verificar si NO tiene repositorio asignado (como el código anterior)
        $alumno = $DB->get_record('usuarios_data_patroller', [
            'id_usuario' => $user->id, 
            'id_materia' => $this->course->id
        ]);
        
        // Si el alumno no existe en la tabla O no tiene repositorio asignado
        if (!$alumno || empty($alumno->id_repo)) {
            $enriched_user = UserHelper::getEnrichedUser($this->course, $user->id);
            $unregistered[] = [
                'sede' => htmlspecialchars($enriched_user->sede ?? ''),
                'curso' => htmlspecialchars($enriched_user->curso ?? ''),
                'username' => htmlspecialchars($user->username),
                'fullname' => htmlspecialchars($user->firstname . ' ' . $user->lastname),
                'email' => htmlspecialchars($user->email),
                'user_id' => $user->id
            ];
        }
    }
    
    return $unregistered;
    }
    
    /**
     * Procesa la selección de repositorio por parte del estudiante
     * @param int $alumnoId ID del estudiante en la tabla patroller
     * @param int $repositorioId ID del repositorio seleccionado
     * @param string $githubUsername Nombre de usuario de GitHub
     * @throws Exception Si el usuario no tiene permisos
     */
    public function processRepositorySelection(int $alumnoId, int $repositorioId, string $githubUsername): void {
        global $DB;
        
    // debug traces removed
        // Validación de seguridad: solo puede editar sus propios datos
        $user_id_from_alumno = $this->getUserIdFromAlumnoId($alumnoId);
        if (!$user_id_from_alumno) {
            throw new \Exception('Usuario no encontrado para el ID proporcionado');
        }
        
        if ($this->userId !== $user_id_from_alumno) {
            throw new \Exception('No tienes permisos para modificar estos datos');
        }
        
        // Actualizar repositorio si se seleccionó uno válido
        if ($repositorioId > 0) {
            $DB->set_field('usuarios_data_patroller', 'id_repo', $repositorioId, ['id' => $alumnoId]);
        }
        
        // Actualizar nombre GitHub si se proporcionó
        if (!empty($githubUsername)) {
            // updating github username
            $this->updateGitHubUsername($alumnoId, $githubUsername);
        }
        
        // processing complete
    }
    
    /**
     * Obtiene repositorios disponibles para la sede y curso específicos
     */
    private function getAvailableRepositories(string $sede, string $curso): array {
        global $DB;
        
        return $DB->get_records('repositorios_data_patroller', [
            'sede' => $sede,
            'curso' => $curso,
            'id_materia' => $this->course->id,
        ]);
    }
    
    /**
     * Obtiene datos de compañeros de clase de la misma sede y curso
     */
    private function getClassmatesData(string $my_sede, string $my_curso, array $available_repos): array {
        global $DB;
        
        $students = $DB->get_records('usuarios_data_patroller', ['id_materia' => $this->course->id]);
        $classmates = [];
        
        foreach ($students as $student) {
            $student_user = UserHelper::getEnrichedUser($this->course, $student->id_usuario);
            if (!$student_user) continue;
            
            // Solo compañeros de la misma sede y curso
            if ($student_user->sede !== $my_sede || $student_user->curso !== $my_curso) continue;
            
            $classmates[] = $this->formatClassmateData($student, $student_user, $available_repos);
        }
        
        return $classmates;
    }
    
    /**
     * Formatea los datos de un compañero para el template
     */
    private function formatClassmateData(object $student, object $student_user, array $available_repos): array {
        global $DB;
        
        $is_current_user = ($student->id_usuario == $this->userId);
        $is_readonly = in_array($student->invitacion_status, [3, 4, 5]);
        $can_edit = $is_current_user && !$is_readonly;
        
        $classmate_data = [
            'id' => $student->id,
            'user_id' => $student->id_usuario,
            // keep legacy 'name' (username) but also expose full_name for filtering/display
            'name' => htmlspecialchars($student->nombre_usuario),
            'full_name' => htmlspecialchars(($student_user->firstname ?? '') . ' ' . ($student_user->lastname ?? '')),
            'github_username' => htmlspecialchars($student->usuario_github),
            'is_current_user' => $is_current_user,
            'is_readonly' => $is_readonly,
            'can_edit' => $can_edit,
            'available_repositories' => $this->formatRepositoriesForSelect($available_repos, $student->id_repo ?? 0),
            'has_available_repos' => !empty($available_repos)
        ];
        
        // Datos del repositorio asignado
        if ($student->id_repo) {
            $repo = $DB->get_record('repositorios_data_patroller', ['id' => $student->id_repo]);
            $classmate_data['repository'] = [
                'id' => $student->id_repo,
                'name' => htmlspecialchars($repo->nombre_repo ?? 'Repositorio no encontrado'),
                'group_number' => htmlspecialchars($repo->num_grupo ?? ''),
                'assigned' => true
            ];
        } else {
            $classmate_data['repository'] = [
                'assigned' => false,
                'name' => get_string('unassigned', 'mod_pluginpatroller')
            ];
        }
        
        return $classmate_data;
    }
    
    /**
     * Convierte repositorios a formato para select HTML
     */
    private function formatRepositoriesForSelect(array $repositories, ?int $selected_repo_id = 0): array {
        $options = [];
        
        // Normalizar el valor si es null
        $selected_repo_id = $selected_repo_id ?? 0;
        
        // Opción por defecto
        $options[] = [
            'value' => '0',
            'text' => 'Seleccionar repositorio...',
            'selected' => ($selected_repo_id == 0)
        ];
        
        foreach ($repositories as $repo) {
            $options[] = [
                'value' => $repo->id,
                'text' => $repo->num_grupo,
                'selected' => ($repo->id == $selected_repo_id)
            ];
        }
        return $options;
    }
    
    /**
     * Verifica si el usuario está registrado en el plugin
     */
    private function isUserRegisteredInPlugin(int $userId): bool {
        global $DB;
        
        return $DB->record_exists('usuarios_data_patroller', [
            'id_usuario' => $userId,
            'id_materia' => $this->course->id
        ]);
    }
    
    /**
     * Determina si el estudiante actual puede editar algún dato
     */
    private function canStudentEdit(array $classmates_data): bool {
        return !empty(array_filter($classmates_data, fn($student) => $student['can_edit']));
    }
    
    /**
     * Obtiene ID de usuario Moodle desde ID de tabla patroller
     */
    private function getUserIdFromAlumnoId(int $alumnoId): ?int {
        global $DB;
        
        $record = $DB->get_record('usuarios_data_patroller', ['id' => $alumnoId], 'id_usuario');
        return $record ? $record->id_usuario : null;
    }
    
    /**
     * Actualiza el nombre de usuario de GitHub y resetea el status de invitación si cambió
     */
    private function updateGitHubUsername(int $alumnoId, string $githubUsername): void {
        global $DB;
        
        $current_github = $DB->get_field('usuarios_data_patroller', 'usuario_github', ['id' => $alumnoId]);

        if ($current_github !== $githubUsername) {
            $DB->set_field('usuarios_data_patroller', 'usuario_github', $githubUsername, ['id' => $alumnoId]);
            // Reset status de invitación al cambiar usuario GitHub
            $DB->set_field('usuarios_data_patroller', 'invitacion_status', 0, ['id' => $alumnoId]);
        }
    }
}
