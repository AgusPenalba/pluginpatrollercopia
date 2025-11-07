<?php
/**
 * ===== File: model/ghpatapi/GitHubRegistrationStatus.php =====
 */
namespace mod_pluginpatroller\model\ghpatapi;

defined('MOODLE_INTERNAL') || die();

/**
 * Clase GitHubRegistrationStatus (UNIFICADA)
 *
 * Define los estados de invitación/colaboración en GitHub
 * Usado tanto para estudiantes como para profesores
 *
 * @package mod_pluginpatroller
 */
abstract class GitHubRegistrationStatus
{
    // ===== ESTADOS CORE (NO MODIFICAR - compatibilidad con código existente) =====
    
    /** @var int Estado sin procesar - no se ha intentado invitar */
    const UNPROCESSED = 0;
    
    /** @var int El usuario no completó su nombre de GitHub */
    const MISSING_USERNAME = 1;
    
    /** @var int El usuario de GitHub no existe */
    const USERNAME_NOT_FOUND = 2;
    
    /** @var int La invitación fue enviada con éxito */
    const INVITATION_SENT = 3;
    
    /** @var int La invitación está pendiente de aceptación */
    const INVITATION_PENDING = 4;
    
    /** @var int La invitación fue aceptada - colaborador activo */
    const ACCEPTED = 5;
    
    /** @var int Error al procesar la invitación */
    const ERROR = 6;

    // ===== ALIASES para compatibilidad con nuevo código =====
    const NOT_PROCESSED = self::UNPROCESSED;
    const USER_NOT_FOUND = self::USERNAME_NOT_FOUND;
    const REPO_NOT_FOUND = self::ERROR; // Se puede expandir si es necesario

    /**
     * Mensajes descriptivos originales (mantener compatibilidad)
     * @var string[]
     */
    public static $messages = [
        self::UNPROCESSED => "La invitación aún no ha sido enviada.",
        self::MISSING_USERNAME => "El alumno no completó el nombre de su usuario en GitHub.",
        self::USERNAME_NOT_FOUND => "El usuario GitHub declarado no existe.",
        self::INVITATION_SENT => "La invitación fue enviada con éxito hace un momento.",
        self::INVITATION_PENDING => "La invitación fue enviada previamente y está pendiente de aceptación.",
        self::ACCEPTED => "La invitación fue aceptada, el usuario ya está en el Repo.",
        self::ERROR => "Error {status} al intentar la registración.",
    ];

    // ===== NUEVOS MÉTODOS PARA RENDERING (extendiendo funcionalidad) =====

    /**
     * Obtiene el texto corto del estado (para tablas/badges)
     */
    public static function getShortText(?int $status): string {
        // PROTECCIÓN: Si status es null, usar UNPROCESSED por defecto
        if ($status === null) {
            $status = self::UNPROCESSED;
        }
        
        return match($status) {
            self::UNPROCESSED => 'Sin procesar',
            self::MISSING_USERNAME => 'Sin usuario',
            self::USERNAME_NOT_FOUND => 'Usuario no encontrado',
            self::INVITATION_SENT => 'Invitación enviada',
            self::INVITATION_PENDING => 'Pendiente',
            self::ACCEPTED => 'Aceptado',
            self::ERROR => 'Error',
            default => 'Desconocido'
        };
    }

    /**
     * Obtiene el mensaje completo del estado
     */
    public static function getMessage(?int $status): string {
        if ($status === null) {
            $status = self::UNPROCESSED;
        }
        return self::$messages[$status] ?? self::$messages[self::ERROR];
    }

    /**
     * Obtiene la clase CSS Bootstrap para badges
     */
    public static function getBadgeClass(?int $status): string {
        if ($status === null) {
            $status = self::UNPROCESSED;
        }
        
        switch ($status) {
            case self::UNPROCESSED:
                return 'badge-secondary';
            case self::MISSING_USERNAME:
            case self::USERNAME_NOT_FOUND:
            case self::ERROR:
                return 'badge-danger';
            case self::INVITATION_SENT:
                return 'badge-info';
            case self::INVITATION_PENDING:
                return 'badge-warning';
            case self::ACCEPTED:
                return 'badge-success';
            default:
                return 'badge-dark';
        }
    }

    /**
     * Obtiene el icono emoji para el estado
     */
    public static function getIcon(?int $status): string {
        if ($status === null) {
            $status = self::UNPROCESSED;
        }
        
        switch ($status) {
            case self::UNPROCESSED:
                return 'fas fa-hourglass-start';
            case self::MISSING_USERNAME:
            case self::USERNAME_NOT_FOUND:
            case self::ERROR:
                return 'fas fa-times-circle';
            case self::INVITATION_SENT:
                return 'fas fa-envelope';
            case self::INVITATION_PENDING:
                return 'fas fa-clock';
            case self::ACCEPTED:
                return 'fas fa-check-circle';
            default:
                return 'fas fa-question-circle';
        }
    }

    /**
     * Determina si el estado permite edición (para checkboxes/inputs)
     */
    public static function isEditable(?int $status): bool {
        if ($status === null) {
            return true; // NULL = sin procesar = editable
        }
        
        return in_array($status, [
            self::UNPROCESSED,
            self::MISSING_USERNAME,
            self::USERNAME_NOT_FOUND,
            self::ERROR
        ]);
    }

    /**
     * Determina si el estado indica que la invitación está activa
     */
    public static function isInvitationActive(?int $status): bool {
        if ($status === null) {
            return false;
        }
        
        return in_array($status, [
            self::INVITATION_SENT,
            self::INVITATION_PENDING
        ]);
    }

    /**
     * Determina si el estado indica colaborador activo
     */
    public static function isAccepted(?int $status): bool {
        return $status === self::ACCEPTED;
    }

    /**
     * Mapea el código HTTP de respuesta de GitHub a un estado
     * (para mantener compatibilidad con inviteStudentToRepo)
     */
    public static function fromHttpCode(int $httpCode): int {
        return match($httpCode) {
            201, 202 => self::INVITATION_SENT,
            204 => self::INVITATION_PENDING, // Ya era colaborador o invitación pendiente
            404 => self::USERNAME_NOT_FOUND,
            403 => self::ERROR, // Permisos insuficientes
            422 => self::USERNAME_NOT_FOUND, // Validación fallida
            default => self::ERROR
        };
    }

    /**
     * Mapea el resultado de checkInvitationStatus a estado final
     * (usado por GitHubAccessService y TeacherRepoService)
     */
    public static function fromInvitationCheck(int $checkResult): int {
        // checkInvitationStatus ya devuelve constantes de esta clase
        return $checkResult;
    }
}