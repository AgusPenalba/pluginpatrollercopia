### Documentación de la Clase `GitHubPatrollerAPI`

A continuación, se presenta la documentación técnica de la clase
`GitHubPatrollerAPI`.

**Diagrama UML**

``` mermaid
classDiagram
    class GitHubPatrollerAPI {
        -string owner
        -string token
        -static string apiBaseUrl
        +__construct(string owner, string token)
        -getHeaders(): array
        -curlRequest(string url, string method, mixed body, array extraHeaders): array
        +userExists(string username): bool
        +checkCollaborationStatus(string repo, string username): bool
        +inviteStudentToRepo(string repoName, string githubUsername): array
        +inviteStudentsByRepoList(array repoList, int courseId, array studentsByRepo): array
        -getRegistrationStatusMessage(int status): string
    }
```

**Descripción**

Esta clase actúa como un cliente de la API de GitHub, facilitando la
interacción para la gestión de colaboradores y la verificación de
estados de registro en repositorios.

**Propiedades**

-   `owner` (privada, `string`): El nombre de usuario o la organización
    propietaria del repositorio.
-   `token` (privada, `string`): El token de acceso personal de GitHub
    para la autenticación.
-   `apiBaseUrl` (privada, `string`): La URL base de la API de GitHub
    ('https://api.github.com'), un valor constante para todas las instancias
    de la clase.

**Métodos Públicos**

-   `__construct(string owner, string token)`: Inicializa el cliente API
    con el propietario del repositorio y un token.
-   `userExists(string username)`: Verifica si un usuario de GitHub
    existe. Retorna `true` si el usuario existe (código HTTP 200), de lo
    contrario, `false`.
-   `checkCollaborationStatus(string repo, string username)`: Verifica
    si un usuario es un colaborador de un repositorio.
-   `inviteStudentToRepo(string repoName, string githubUsername)`: Envía
    una invitación de colaboración a un usuario para un repositorio.
-   `inviteStudentsByRepoList(array repoList, int courseId, array studentsByRepo)`:
    Invita a un grupo de estudiantes a los repositorios indicados y
    actualiza su estado. Retorna un array con el resultado de cada
    intento.
-   `getRegistrationStatusMessage(int status)`: Retorna el mensaje de
    estado de registro de un estudiante en formato legible.

  **Métodos Públicos**
  
  Usa la clase `GitHubRegistrationStatus`.

------------------------------------------------------------------------

### Documentación de la Clase `GitHubRegistrationStatus`

A continuación, se presenta la documentación técnica de la clase
`GitHubRegistrationStatus`.

**Diagrama UML**

``` mermaid
classDiagram
    class GitHubRegistrationStatus {
        +const UNPROCESSED = 0
        +const MISSING_USERNAME = 1
        +const USERNAME_NOT_FOUND = 2
        +const INVITATION_SENT = 3
        +const INVITATION_PENDING = 4
        +const ACCEPTED = 5
        +const ERROR = 6
        +static array messages
    }
```

**Descripción**

Esta clase emula un `Enum` para versiones de PHP anteriores a 8.1.
Define los posibles estados de registro de un estudiante en un
repositorio de GitHub utilizando constantes de clase.

**Constantes**

-   `UNPROCESSED`: Estado sin procesar.
-   `MISSING_USERNAME`: El alumno no completó el nombre de usuario de
    GitHub.
-   `USERNAME_NOT_FOUND`: El usuario de GitHub no existe o aún no ha
    sido creado.
-   `INVITATION_SENT`: La invitación fue enviada con éxito.
-   `INVITATION_PENDING`: La invitación fue enviada previamente y está
    pendiente de aceptación.
-   `ACCEPTED`: La invitación fue aceptada y el usuario ya es un
    colaborador.
-   `ERROR`: Ocurrió un error al intentar el registro.

**Propiedades Estáticas**

-   `messages` (`static array`): Un array estático que mapea los códigos
    de estado a mensajes descriptivos, facilitando la conversión de
    códigos a mensajes legibles.
