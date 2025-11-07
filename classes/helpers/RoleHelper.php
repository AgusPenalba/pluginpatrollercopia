<?php
namespace mod_pluginpatroller\helpers;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper centralizado para verificación de roles
 * Reemplaza funcionalidad duplicada y proporciona verificación consistente
 */
class RoleHelper {
    
    // Constantes de roles estándar de Moodle
    const ROLE_TEACHER_ID = 3;
    const ROLE_STUDENT_ID = 5;
    const ROLE_EDITING_TEACHER = 'editingteacher';
    const ROLE_STUDENT = 'student';
    
    // Cache estático para evitar consultas repetidas
    private static array $roleCache = [];
    private static array $userRoleCache = [];
    
    /**
     * Verifica si un usuario tiene un rol específico por shortname
     * @param string $roleshortname Nombre corto del rol ('student', 'editingteacher', etc.)
     * @param int $userid ID del usuario
     * @param object $context Contexto donde verificar el rol
     * @return bool
     */
    public static function hasRole(string $roleshortname, int $userid, object $context): bool {
        $cacheKey = "{$roleshortname}_{$userid}_{$context->id}";
        
        if (isset(self::$userRoleCache[$cacheKey])) {
            return self::$userRoleCache[$cacheKey];
        }
        
        $userroles = get_user_roles($context, $userid);
        $hasRole = false;
        
        foreach ($userroles as $role) {
            if ($role->shortname === $roleshortname) {
                $hasRole = true;
                break;
            }
        }
        
        self::$userRoleCache[$cacheKey] = $hasRole;
        return $hasRole;
    }
    
    /**
     * Verifica si un usuario es estudiante por ID de rol
     * @param int $userid ID del usuario  
     * @param object $context Contexto donde verificar
     * @return bool
     */
    public static function isStudent(int $userid, object $context): bool {
        $result = user_has_role_assignment($userid, self::ROLE_STUDENT_ID, $context->id);
        return $result;
    }
    
    /**
     * Verifica si un usuario es profesor por ID de rol
     * @param int $userid ID del usuario
     * @param object $context Contexto donde verificar  
     * @return bool
     */
    public static function isTeacher(int $userid, object $context): bool {
        return user_has_role_assignment($userid, self::ROLE_TEACHER_ID, $context->id);
    }
    
    /**
     * Verifica si un usuario es estudiante por shortname
     * @param int $userid ID del usuario
     * @param object $context Contexto donde verificar
     * @return bool
     */
    public static function isStudentByName(int $userid, object $context): bool {
        return self::hasRole(self::ROLE_STUDENT, $userid, $context);
    }
    
    /**
     * Verifica si un usuario es profesor editor por shortname
     * @param int $userid ID del usuario  
     * @param object $context Contexto donde verificar
     * @return bool
     */
    public static function isEditingTeacher(int $userid, object $context): bool {
        return self::hasRole(self::ROLE_EDITING_TEACHER, $userid, $context);
    }
    
    /**
     * Obtiene todos los roles de un usuario en un contexto
     * @param int $userid ID del usuario
     * @param object $context Contexto
     * @return array Array de roles del usuario
     */
    public static function getUserRoles(int $userid, object $context): array {
        return get_user_roles($context, $userid);
    }
    
    /**
     * Categoriza un usuario según su rol principal
     * @param int $userid ID del usuario
     * @param object $context Contexto
     * @return string 'student', 'teacher', 'editingteacher' o 'other'
     */
    public static function categorizeUser(int $userid, object $context): string {
        if (self::isEditingTeacher($userid, $context)) {
            return 'editingteacher';
        } elseif (self::isTeacher($userid, $context)) {
            return 'teacher';
        } elseif (self::isStudent($userid, $context)) {
            return 'student';
        }
        
        return 'other';
    }
    
    /**
     * Limpia el cache de roles
     */
    public static function clearCache(): void {
        self::$roleCache = [];
        self::$userRoleCache = [];
    }
}
