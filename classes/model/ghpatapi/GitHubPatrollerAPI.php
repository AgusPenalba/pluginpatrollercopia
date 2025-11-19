<?php
/**
 * ===== File: model/ghpatapi/GitHubPatrollerAPI.php =====
 */
namespace mod_pluginpatroller\model\ghpatapi;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;

/**
 * Clase GitHubPatrollerAPI
 *
 * Cliente API para interactuar con la API de GitHub.
 * Se especializa en la gestión de colaboradores (estudiantes) y la verificación de
 * estados de registro en repositorios.
 *
 * @package mod_pluginpatroller
 * @class GitHubPatrollerAPI
 */
class GitHubPatrollerAPI
{
    /**
     * El nombre de usuario o la organización propietaria del repositorio.
     *
     * @var string
     */
    private string $owner;

    /**
     * El token de acceso personal (Personal Access Token) de GitHub para la autenticación.
     *
     * @var string
     */
    private string $token;

    /**
     * La URL base de la API de GitHub.
     *
     * Esta propiedad es estática porque su valor es constante para todas las instancias
     * de la clase.
     *
     * @var string
     */
    private static string $apiBaseUrl = 'https://api.github.com';

    /**
     * Constructor de la clase GitHubPatrollerAPI.
     *
     * Inicializa el cliente API con el propietario del repositorio y un token de
     * autenticación.
     *
     * @param string $owner El nombre de usuario o la organización.
     * @param string $token El token de acceso personal.
     */
    public function __construct(string $owner, string $token)
    {
        $this->owner = $owner;
        $this->token = $token;
    }

    /**
     * Realiza una petición cURL genérica a la API de GitHub.
     *
     * @param string $url La URL completa de la petición.
     * @param string $method El método HTTP (GET, POST, PUT, DELETE).
     * @param string|null $payload Los datos a enviar en peticiones POST/PUT.
     * @param array $headers Headers adicionales para la petición.
     * @return array Un array asociativo con la respuesta de la API, el código HTTP y el error.
     */
    private function curlRequest(string $url, string $method = 'GET', ?string $payload = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($payload) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $defaultHeaders = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: GitHub-API-Request',
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'body'      => $response,
            'httpCode'  => $httpCode,
            'error'     => $curlError,
        ];
    }
    
    /**
     * Crea un repositorio en GitHub.
     *
     * @param string $repoName El nombre del repositorio a crear.
     * @return bool Retorna true si el repositorio fue creado con éxito (código 201).
     */
    public function createRepository(string $repoName): bool
    {
        $url = self::$apiBaseUrl . '/orgs/' . urlencode($this->owner) . '/repos';
        $data = ['name' => $repoName, 'private' => true, 'auto_init' => true];
        $payload = json_encode($data);
        $result = $this->curlRequest($url, 'POST', $payload, ['Content-Type: application/json']);
        $created = ($result['httpCode'] === 201);
        return $created;
    }
    
    /**
     * Elimina un repositorio de GitHub.
     *
     * @param string $repoName El nombre del repositorio a eliminar.
     * @return bool Retorna true si el repositorio fue eliminado con éxito (código 204).
     * @throws \Exception Si hay error en la eliminación.
     */
    public function deleteRepository(string $repoName): bool
    {
        $url = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName);
        $result = $this->curlRequest($url, 'DELETE');
        
        if ($result['httpCode'] === 204) {
            // 204 No Content = eliminación exitosa
            return true;
        } elseif ($result['httpCode'] === 404) {
            // 404 Not Found = el repositorio ya no existe (puede considerarse "éxito")
            return true;
        } else {
            // Cualquier otro código es un error
            $error_body = $result['body'] ?? 'Sin detalles del error';
            throw new \Exception("Error eliminando repositorio '$repoName': HTTP {$result['httpCode']} - $error_body");
        }
    }
    
    /**
     * Verifica si un repositorio existe en la organización.
     *
     * @param string $repoName El nombre del repositorio a verificar.
     * @return bool Retorna true si el repositorio existe, false en caso contrario.
     */
    public function checkRepositoryExistence(string $repoName): bool
    {
        $url = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName);
        
        $result = $this->curlRequest($url);
        
        return ($result['httpCode'] === 200);
    }
    
    /**
     * Chequea el estado de la API de GitHub.
     *
     * @return bool Retorna true si la API está accesible, false en caso contrario.
     */
    public function checkApiStatus(): bool
    {
        $result = $this->curlRequest(self::$apiBaseUrl);
        return ($result['httpCode'] === 200);
    }

    /**
     * Verifica si un usuario de GitHub existe.
     *
     * @param string $username El nombre de usuario de GitHub a verificar.
     * @return bool Retorna true si el usuario existe, false en caso contrario.
     */
    public function checkGithubUsername(string $username): bool
    {
        $url = self::$apiBaseUrl . '/users/' . urlencode($username);
        $result = $this->curlRequest($url);

        return ($result['httpCode'] === 200);
    }
    
    /**
     * Invita a un colaborador a un repositorio.
     *
     * @param string $repoName El nombre del repositorio.
     * @param string $username El nombre de usuario de GitHub del colaborador.
     * @return bool Retorna true si la invitación fue exitosa o ya existe.
     */
    public function inviteCollaborator(string $repoName, string $username): bool
    {
        $url = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName) . '/collaborators/' . urlencode($username);
       
        $result = $this->curlRequest($url, 'PUT');
              
        // 201: Invitación enviada, 204: Usuario ya es colaborador o invitación pendiente
        $success = in_array($result['httpCode'], [201, 204]);
        
        if (!$success) {
            $response_data = json_decode($result['body'], true);
            switch ($result['httpCode']) {
                case 404:
                    throw new \Exception("Repositorio '$repoName' no encontrado o sin acceso. Verifica que el repositorio existe y que el token tiene permisos.");
                case 403:
                    throw new \Exception("Permisos denegados o límite de API excedido. Verifica que el token tiene permisos de 'admin' en el repositorio '$repoName'.");
                case 422:
                    throw new \Exception("Error de validación: usuario '$username' no existe o datos inválidos.");
                default:
                    throw new \Exception("Error de API GitHub (código {$result['httpCode']}): " . ($response_data['message'] ?? 'Error desconocido'));
            }
        }
        
        return $success;
    }

        /**
     * Verifica si un usuario de GitHub existe.
     *
     * @param string $username El nombre de usuario de GitHub a verificar.
     * @return bool Retorna true si el usuario existe (código HTTP 200), de lo contrario, false.
     */
    public function userExists(string $username): bool
    {
        $url = self::$apiBaseUrl . '/users/' . urlencode($username);
        
        $result = $this->curlRequest($url);
        $exists = ($result['httpCode'] === 200);
                
        if (!$exists) {            
            // Detectar errores específicos
            if (!empty($result['error'])) {
                throw new \Exception("Error de conectividad verificando usuario '$username': " . $result['error']);
            }
            
            switch ($result['httpCode']) {
                case 403:
                    throw new \Exception("Límite de API excedido al verificar usuario '$username'. Intenta más tarde.");
                case 404:
                    // Usuario no existe - esto es normal, simplemente retornamos false
                    break;
                default:
                    if ($result['httpCode'] >= 500) {
                        throw new \Exception("Error del servidor GitHub al verificar usuario '$username' (código {$result['httpCode']})");
                    }
            }
        }
        
        return $exists;
    }
    
    /**
     * Chequea el estado de la invitación de un colaborador a un repositorio.
     *
     * @param string $repoName El nombre del repositorio.
     * @param string $username El nombre de usuario de GitHub del colaborador.
     * @return int Retorna un código de estado de GitHubRegistrationStatus.
     */
    public function checkInvitationStatus(string $repoName, string $username): int
    {
        $status = GitHubRegistrationStatus::ERROR;

        $url = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName) . '/collaborators/' . urlencode($username);
        $result = $this->curlRequest($url, 'GET');

        if ($result['httpCode'] === 204) {
            $urlInvitation = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName) . '/invitations';
            $invitationsResult = $this->curlRequest($urlInvitation, 'GET');
            $invitations = json_decode($invitationsResult['body'], true);

            $status = GitHubRegistrationStatus::ACCEPTED;
            if (is_array($invitations)) {
                reset($invitations); // Asegura que el puntero interno del array esté al inicio.
                // corta al cambiar de estado
                while (($invitation = current($invitations)) && $status === GitHubRegistrationStatus::ACCEPTED) {
                    if ($invitation['invitee']['login'] === $username) {
                        $status = GitHubRegistrationStatus::INVITATION_PENDING;
                    }
                    next($invitations);
                }
            }
        } elseif ($result['httpCode'] === 404) {
            $status = GitHubRegistrationStatus::UNPROCESSED;
        }
        
        return $status;
    }

    /**
     * Lista todas las invitaciones pendientes de un repositorio
     *
     * @param string $repoName El nombre del repositorio.
     * @return array Lista de invitaciones pendientes
     */
    public function listPendingInvitations(string $repoName): array
    {
        $url = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName) . '/invitations';
                
        $result = $this->curlRequest($url, 'GET');
                
        if ($result['httpCode'] === 200) {
            $invitations = json_decode($result['body'], true);
            return is_array($invitations) ? $invitations : [];
        }
        
        return [];
    }

    /**
     * Lista todos los colaboradores actuales de un repositorio
     *
     * @param string $repoName El nombre del repositorio.
     * @return array Lista de colaboradores
     */
    public function listCollaborators(string $repoName): array
    {
        $url = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName) . '/collaborators';
                
        $result = $this->curlRequest($url, 'GET');
              
        if ($result['httpCode'] === 200) {
            $collaborators = json_decode($result['body'], true);
            return is_array($collaborators) ? $collaborators : [];
        }
        
        return [];
    }

    /**
     * Envía una invitación de colaboración a un usuario para un repositorio.
     *
     * @param string $repoName El nombre del repositorio.
     * @param string $githubUsername El nombre de usuario de GitHub del estudiante.
     * @return array El resultado de la solicitud cURL.
     */
    public function inviteStudentToRepo(string $repoName, string $githubUsername): array
    {
        $url = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName) . '/collaborators/' . urlencode($githubUsername);
        return $this->curlRequest($url, 'PUT', null, ['Content-Length: 0']);
    }

    /**
     * Retorna el mensaje de estado de registro de un estudiante en formato legible.
     *
     * @param int $status El código de estado de registro.
     * @return string El mensaje de estado correspondiente.
     */
    public static function getRegistrationStatusMessage(int $status): string
    {
        $message = "";
        if (array_key_exists($status, GitHubRegistrationStatus::$messages)) {
            $message = GitHubRegistrationStatus::$messages[$status];
        } else {
            $message = str_replace("{status}", (string) $status, GitHubRegistrationStatus::$messages[GitHubRegistrationStatus::ERROR]);
        }
        return $message;
    }

    /**
     * Obtiene la lista de commits realizados por un usuario en un repositorio desde una fecha.
     *
     * @param string $repoName Nombre del repositorio.
     * @param string $author Usuario de GitHub.
     * @param string $sinceDate Fecha inicial en formato ISO8601 (ej: '2024-01-01T00:00:00Z').
     * @return array Lista de commits (array asociativo).
     */
    public function getCommitsByRepoAndUser(string $repoName, string $author, string $sinceDate): array
    {
        $url = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName) . '/commits'
            . '?author=' . urlencode($author)
            . '&since=' . urlencode($sinceDate)
            . '&per_page=100';

        $result = $this->curlRequest($url, 'GET');
        if ($result['httpCode'] !== 200) {
            return [];
        }

        $commits = json_decode($result['body'], true);
        return is_array($commits) ? $commits : [];
    }

    /**
     * Obtiene las estadísticas de un commit específico (líneas agregadas, borradas, modificadas).
     *
     * @param string $repoName Nombre del repositorio.
     * @param string $sha SHA del commit.
     * @return array Array con las estadísticas del commit.
     */
    public function getCommitStats(string $repoName, string $sha): array
    {
        $url = self::$apiBaseUrl . '/repos/' . urlencode($this->owner) . '/' . urlencode($repoName) . '/commits/' . urlencode($sha);

        $result = $this->curlRequest($url, 'GET');
        if ($result['httpCode'] !== 200) {
            return [];
        }

        $commitData = json_decode($result['body'], true);
        return $commitData['stats'] ?? [];
    }

    /**
     * Verifica el estado de un colaborador en un repositorio
     * 
     * @param string $repoName Nombre del repositorio
     * @param string $username Nombre de usuario GitHub
     * @return int Código de estado según GitHubRegistrationStatus
     */
    public function checkCollaboratorStatus(string $repoName, string $username): int {
        if (empty($repoName) || empty($username)) {
            return GitHubRegistrationStatus::ERROR;
        }
        
        // Comprobar si el usuario existe
        if (!$this->userExists($username)) {
            return GitHubRegistrationStatus::USERNAME_NOT_FOUND;
        }
        
        // Comprobar si el repositorio existe
        if (!$this->checkRepositoryExistence($repoName)) {
            return GitHubRegistrationStatus::ERROR;
        }
        
        // Consultar si es colaborador activo
        $url = self::$apiBaseUrl . "/repos/{$this->owner}/{$repoName}/collaborators/{$username}";
        $headers = ['Accept: application/vnd.github.v3+json'];
        
        $result = $this->curlRequest($url, 'GET', null, $headers);
        $code = $result['httpCode'];
        
        // 204 significa que es colaborador
        if ($code == 204) {
            return GitHubRegistrationStatus::ACCEPTED;
        }
        
        // Verificar si hay una invitación pendiente
        $url = self::$apiBaseUrl . "/repos/{$this->owner}/{$repoName}/invitations";
        $result = $this->curlRequest($url, 'GET', null, $headers);
        $code = $result['httpCode'];
        
        if ($code == 200) {
            $invitations = json_decode($result['body'], true);
            if (is_array($invitations)) {
                foreach ($invitations as $invitation) {
                    if (isset($invitation['invitee']['login']) && $invitation['invitee']['login'] === $username) {
                        return GitHubRegistrationStatus::INVITATION_PENDING;
                    }
                }
            }
        }
        
        // No es colaborador ni tiene invitación pendiente
        return GitHubRegistrationStatus::UNPROCESSED;
    }

    /**
     * Elimina un colaborador de un repositorio
     * 
     * @param string $repoName Nombre del repositorio
     * @param string $username Nombre de usuario GitHub
     * @return bool Éxito de la operación
     */
    public function removeCollaborator(string $repoName, string $username): bool {
        if (empty($repoName) || empty($username)) {
            return false;
        }
        
        $url = self::$apiBaseUrl . "/repos/{$this->owner}/{$repoName}/collaborators/{$username}";
        $headers = ['Accept: application/vnd.github.v3+json'];
        
        $result = $this->curlRequest($url, 'DELETE', null, $headers);
        $code = $result['httpCode'];
        
        // 204 significa éxito en la eliminación
        return $code == 204;
    }
 /**
     * Invita a un grupo de estudiantes a los repositorios indicados y actualiza su estado.
     *
     * Este método itera sobre una lista de repositorios y estudiantes, verifica el estado de cada estudiante
     * y envía invitaciones si es necesario. Retorna un array con el resultado de cada intento.
     *
     * @param array $repoList Un array de IDs de repositorios a nombres de repositorios.
     * @param array $studentsByRepo Un array que mapea IDs de repositorios a una lista de objetos de estudiante.
     * @return array Un array asociativo donde la clave es el ID del estudiante y el valor es un array
     * con el nombre, el repositorio y el estado de la invitación.
     */

 
    // public function inviteStudentsByRepoList(array $repoList, array $studentsByRepo): array
    // {
    //     global $DB;
    //     $results = [];

    //     foreach ($repoList as $repoId => $repoName) {
    //         $students = $studentsByRepo[$repoId] ?? [];

    //         foreach ($students as $student) {
    //             $githubUsername = $student->usuario_github;
    //             $status = GitHubRegistrationStatus::UNPROCESSED; // Valor por defecto, sin procesar

    //             // Asignamos el estatus en una estructura de control limpia
    //             if (empty($githubUsername)) {
	// 				// No tiene usuario
    //                 $status = GitHubRegistrationStatus::MISSING_USERNAME;
    //             } elseif (!$this->userExists($githubUsername)) {
	// 				// Tiene usuario declarado pero este no existe en GitHub
    //                 $status = GitHubRegistrationStatus::USERNAME_NOT_FOUND;
    //             } elseif ((int)$student->invitacion_status === GitHubRegistrationStatus::ACCEPTED) {
	// 				// Ya está registrado
	// 				 $status = GitHubRegistrationStatus::ACCEPTED;
    //             } elseif (in_array((int)$student->invitacion_status, [GitHubRegistrationStatus::INVITATION_SENT, GitHubRegistrationStatus::INVITATION_PENDING], true)) {
	// 				// Se chequea el status para ver si necesita actualización
    //                 $status = $this->checkInvitationStatus($repoName, $githubUsername);
    //             } else {
	// 				// Se realiza la invitación y se guarda el estado correspondiente
    //                 $inviteResult = $this->inviteStudentToRepo($repoName, $githubUsername);
    //                 $httpCode = $inviteResult['httpCode'];
    //                 if (in_array($httpCode, [201, 202, 204], true)) {
    //                     $status = GitHubRegistrationStatus::INVITATION_SENT;
    //                 } else {
    //                     $status = $httpCode;
    //                 }
    //             }
                
    //             // Creamos el array de resultado una sola vez para este estudiante
    //             $results[$student->id] = [
    //                 'name'    => $student->nombre_usuario,
    //                 'repo'    => $repoName,
    //                 'status'  => $status,
    //             ];
                
    //             $DB->update_record('usuarios_data_patroller', [
    //                 'id' => $student->id,
    //                 'invitacion_status' => $status,
    //             ], false);
    //         }
    //     }
    //     return $results;
    // }

    /**
     * Verifica si un usuario es colaborador activo de un repositorio
     */
    public function isCollaborator(string $repoName, string $username): bool {
        try {
            $collaborators = $this->listCollaborators($repoName);
            foreach ($collaborators as $collaborator) {
                if (strtolower($collaborator['login']) === strtolower($username)) {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            error_log("Error verificando colaborador: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene el ID de una invitación pendiente para un usuario específico
     */
    public function getPendingInvitationId(string $repoName, string $username): ?int {
        try {
            $pending_invitations = $this->listPendingInvitations($repoName);
            foreach ($pending_invitations as $invitation) {
                $invitation_login = $invitation['invitee']['login'] ?? $invitation['login'] ?? '';
                if (strtolower($invitation_login) === strtolower($username)) {
                    return $invitation['id'] ?? null;
                }
            }
            return null;
        } catch (\Exception $e) {
            error_log("Error obteniendo ID de invitación pendiente: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene detalles completos de una invitación pendiente (incluyendo fecha)
     */
    public function getPendingInvitationDetails(string $repoName, string $username): ?array {
        try {
            $pending_invitations = $this->listPendingInvitations($repoName);
            foreach ($pending_invitations as $invitation) {
                $invitation_login = $invitation['invitee']['login'] ?? $invitation['login'] ?? '';
                if (strtolower($invitation_login) === strtolower($username)) {
                    return [
                        'id' => $invitation['id'] ?? null,
                        'created_at' => $invitation['created_at'] ?? null,
                        'inviter' => $invitation['inviter']['login'] ?? null,
                        'permissions' => $invitation['permissions'] ?? 'push'
                    ];
                }
            }
            return null;
        } catch (\Exception $e) {
            error_log("Error obteniendo detalles de invitación pendiente: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Cancela una invitación específica por su ID
     */
    public function cancelInvitationById(string $repoName, int $invitationId): bool {
        try {
            $url = self::$apiBaseUrl . "/repos/{$this->owner}/{$repoName}/invitations/{$invitationId}";
            
            $result = $this->curlRequest($url, 'DELETE');
            
            // GitHub devuelve 204 No Content para cancelaciones exitosas
            return $result['httpCode'] === 204;
            
        } catch (\Exception $e) {
            error_log("Error cancelando invitación ID {$invitationId}: " . $e->getMessage());
            return false;
        }
    }

}