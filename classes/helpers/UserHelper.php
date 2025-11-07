<?php
namespace mod_pluginpatroller\helpers;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\UserModel;

/*Centraliza operaciones comunes con usuarios Moodle + datos específicos del plugin*/
class UserHelper {
    
    /*Agrega sede y curso a datos de usuario - Recibe usuario(s) de Moodle y les agrega contexto académico*/
    public static function enrichWithSedeAndCurso($course, $user_data) {
        if (is_array($user_data)) {
            foreach ($user_data as &$user) {
                if (is_object($user) && isset($user->id)) {
                    $user->sede = UserModel::get_sede_by_user($course, $user);
                    $user->curso = UserModel::get_grupo_by_user($course, $user);
                }
            }
            return $user_data;
        } else {
            if (is_object($user_data) && isset($user_data->id)) {
                $user_data->sede = UserModel::get_sede_by_user($course, $user_data);
                $user_data->curso = UserModel::get_grupo_by_user($course, $user_data);
            }
            return $user_data;
        }
    }
    
    /*Obtiene múltiples usuarios de una vez*/
    public static function getUsersBatch(array $user_ids): array {
        global $DB;
        
        if (empty($user_ids)) {
            return [];
        }
        
        [$in_sql, $params] = $DB->get_in_or_equal($user_ids, SQL_PARAMS_NUMBERED);
        $sql = "SELECT * FROM {user} WHERE id $in_sql";
        
        $users = $DB->get_records_sql($sql, $params);
        
        return $users;
    }
    
    /*Combina batch loading + enriquecimiento*/
    public static function getEnrichedUsersBatch($course, array $user_ids): array {
        $users = self::getUsersBatch($user_ids);
        return self::enrichWithSedeAndCurso($course, $users);
    }
    
    /*Obtiene un usuario y lo enriquece*/
    public static function getEnrichedUser($course, int $user_id): ?object {
        global $DB;
        
        $user = $DB->get_record('user', ['id' => $user_id]);
        if (!$user) {
            return null;
        }
        
        return self::enrichWithSedeAndCurso($course, $user);
    }
    
    /*Crea string legible "Sede - Curso"*/
    public static function getAcademicLabel(?string $sede, ?string $curso): string {
        $sede = $sede ?? 'Sin sede';
        $curso = $curso ?? 'Sin curso';
        
        return htmlspecialchars("{$sede} - {$curso}");
    }
    
    /*Divide clave de grupo en componentes*/
    public static function splitGroupKey(string $group_key): array {
        $parts = explode('-', $group_key, 2);
        return [
            $parts[0] ?? '',
            $parts[1] ?? ''
        ];
    }
    
    /* Crea clave de grupo desde componentes*/
    public static function buildGroupKey(string $sede, string $curso): string {
        return trim($sede) . '-' . trim($curso);
    }
}
