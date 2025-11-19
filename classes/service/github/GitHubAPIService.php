<?php
namespace mod_pluginpatroller\service\github;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\helpers\ConfigHelper;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;

/**
 * Servicio base para la API de GitHub:
 * - Instanciar y gestionar la conexión con la API de GitHub
 * - Proveer acceso centralizado a la API para otros servicios
 * - Validar configuración y conectividad
 */
class GitHubApiService {
    
    private $githubAPI;

    public function __construct() {
        //Inicializa la conexión con GitHub
        $this->githubAPI = $this->instanceAPI(); 
    }
    
    // Instancia la API de GitHub
    private function instanceAPI(): ?GitHubPatrollerAPI {
        $token = ConfigHelper::getGitHubToken();
        $owner = ConfigHelper::getGitHubOwner();
        if ($token && $owner) {
            return new GitHubPatrollerAPI($owner, $token);
        }
        return null;
    }
    
    //Obtiene la instancia de la API
    public function getAPI(): ?GitHubPatrollerAPI {
        return $this->githubAPI;
    }
    
    // Prueba la conectividad con GitHub
    public function testConnection(): string {
        if (!$this->githubAPI) {
            return "API de GitHub no configurada";
        }
        
        try {
            // Test simple: verificar si un usuario conocido existe
            $test_user_exists = $this->githubAPI->userExists('github');
            return $test_user_exists ? "Conectividad OK" : "Error de conectividad";
        } catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }

    //Obtiene una instancia válida de la API o lanza excepción
    public function getAPIOrFail(): GitHubPatrollerAPI {
        if (!$this->githubAPI) {
            throw new \Exception(get_string('pluginnotconfigured', 'mod_pluginpatroller'));
        }
        return $this->githubAPI;
    }

    //Ejecuta un diagnóstico completo del sistema GitHub
    public function runDiagnostics(int $course_id): string {
        global $DB;
        $results = [];

        try {
            // Test 1: Verificar configuración
            $owner = \mod_pluginpatroller\helpers\ConfigHelper::getGitHubOwner();
            $token = \mod_pluginpatroller\helpers\ConfigHelper::getGitHubToken();
        
            $results[] = "📋 <strong>Configuración:</strong>";
            $results[] = "- Owner: " . ($owner ? "Configurado ($owner)" : "No configurado");
            $results[] = "- Token: " . ($token ? "Configurado (" . substr($token, 0, 10) . "...)" : "❌ No configurado");
        
            if (!$owner || !$token) {
                $results[] = "<strong>Error:</strong> Configuración incompleta";
                return implode('<br>', $results);
            }
            
            // Test 2: Verificar conectividad básica
            $results[] = "<br><strong>Test de Conectividad:</strong>";
            $connectivity_test = $this->testConnection();
            $results[] = $connectivity_test;
            
            // Test 3: Listar repositorios del curso
            $results[] = "<br><strong>Repositorios del curso:</strong>";
            $repositories = \mod_pluginpatroller\model\RepositoryModel::getAllByCourseId($course_id);
            
            if (empty($repositories)) {
                $results[] = "No hay repositorios configurados para este curso";
            } else {
                $results[] = "- Total: " . count($repositories) . " repositorios";
                foreach ($repositories as $id => $name) {
                    $results[] = "  • $name (ID: $id)";
                }
            }
            
            // Test 4: Estudiantes con GitHub username
            $results[] = "<br><strong>Estudiantes:</strong>";
            $students = $DB->get_records('usuarios_data_patroller', ['id_materia' => $course_id]);
            $with_github = array_filter($students, fn($s) => !empty($s->usuario_github));
            
            $results[] = "- Total estudiantes: " . count($students);
            $results[] = "- Con GitHub username: " . count($with_github);
            
            if (count($with_github) > 0) {
                $results[] = "- Ejemplos:";
                $sample = array_slice($with_github, 0, 3);
                foreach ($sample as $student) {
                    $status_text = match($student->invitacion_status) {
                        0 => get_string('status_unprocessed', 'mod_pluginpatroller'),
                        1 => get_string('status_sent', 'mod_pluginpatroller'), 
                        2 => get_string('status_error', 'mod_pluginpatroller'),
                        3 => get_string('status_accepted', 'mod_pluginpatroller'),
                        default => get_string('status_unknown', 'mod_pluginpatroller')
                    };
                    $results[] = "  • {$student->usuario_github} - $status_text";
                }
            }
            
            // Test 5: Verificar invitaciones reales en GitHub
            $results[] = "<br><strong>Verificación de invitaciones en GitHub:</strong>";
            
            if (!empty($repositories) && count($with_github) > 0) {
                // Tomar el primer repositorio y estudiante para verificar
                $first_repo = array_values($repositories)[0];
                $first_student = array_values($with_github)[0];
                
                $results[] = "- Verificando repo: '$first_repo'";
                $results[] = "- Usuario: '{$first_student->usuario_github}'";
                
                try {
                    if (!$this->githubAPI) {
                        $results[] = "API de GitHub no configurada";
                    } else {
                        // Listar invitaciones pendientes
                        $pending = $this->githubAPI->listPendingInvitations($first_repo);
                        $results[] = "- Invitaciones pendientes: " . count($pending);
                        
                        if (!empty($pending)) {
                            foreach ($pending as $inv) {
                                $invitee = $inv['invitee']['login'] ?? 'unknown';
                                $results[] = "  • $invitee (ID: {$inv['id']})";
                            }
                        }
                        
                        // Listar colaboradores actuales
                        $collaborators = $this->githubAPI->listCollaborators($first_repo);
                        $results[] = "- Colaboradores actuales: " . count($collaborators);
                        
                        $student_is_collaborator = false;
                        foreach ($collaborators as $collab) {
                            if ($collab['login'] === $first_student->usuario_github) {
                                $student_is_collaborator = true;
                                $results[] = " {$first_student->usuario_github} ya es colaborador";
                                break;
                            }
                        }
                        
                        if (!$student_is_collaborator) {
                            $student_has_invitation = false;
                            foreach ($pending as $inv) {
                                if ($inv['invitee']['login'] === $first_student->usuario_github) {
                                    $student_has_invitation = true;
                                    $results[] = " {$first_student->usuario_github} tiene invitación pendiente (ID: {$inv['id']})";
                                    break;
                                }
                            }
                            
                            if (!$student_has_invitation) {
                                $results[] = " {$first_student->usuario_github} no tiene invitación pendiente ni es colaborador";
                            }
                        }
                    }
                    
                } catch (\Exception $verify_error) {
                    $results[] = "Error verificando invitaciones: " . $verify_error->getMessage();
                }
            } else {
                $results[] = "No hay repositorios o estudiantes para verificar";
            }

            $results[] = "<br> <strong>" . get_string('testcompletedsuccessfully', 'mod_pluginpatroller') . "</strong>";
            
        } catch (\Exception $e) {
            $results[] = " <strong>Error en test:</strong> " . $e->getMessage();
        }
        
        return implode('<br>', $results);
    }

}

