<?php
namespace mod_pluginpatroller\helpers;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper para gestionar la configuración del plugin GitPatroller
 */
class ConfigHelper {
    
    /**
     * Obtiene el token de GitHub configurado
     * @return string|null
     */
    public static function getGitHubToken(): ?string {
        $token = get_config('pluginpatroller', 'token_patroller');
        return !empty($token) ? $token : null;
    }
    
    /**
     * Obtiene el owner de GitHub configurado
     * @return string|null
     */
    public static function getGitHubOwner(): ?string {
        $owner = get_config('pluginpatroller', 'owner_patroller');
        return !empty($owner) ? $owner : null;
    }
    
    /**
     * Verifica si GitHub está correctamente configurado
     * @return bool
     */
    public static function isGitHubConfigured(): bool {
        return !empty(self::getGitHubToken()) && !empty(self::getGitHubOwner());
    }
    
    /**
     * Obtiene todas las configuraciones de GitHub en un array
     * @return array
     */
    public static function getGitHubConfig(): array {
        return [
            'token' => self::getGitHubToken(),
            'owner' => self::getGitHubOwner(),
            'configured' => self::isGitHubConfigured()
        ];
    }
    
    /**
     * Obtiene el máximo de usuarios por grupo sugerido
     * @return int
     */
    public static function getMaxUsersPerGroup(): int {
        $maxUsers = get_config('pluginpatroller', 'max_users_per_group');
        return !empty($maxUsers) ? (int)$maxUsers : 5; // valor por defecto
    }
}
