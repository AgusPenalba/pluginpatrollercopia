<?php
namespace mod_pluginpatroller;

defined('MOODLE_INTERNAL') || die();

class RoleChecker {
    private $context;
    private $roles;

    /**
     * Constructor
     * @param int $courseid ID del curso donde se van a chequear roles
     */
    public function __construct($context, int $courseid) {
        global $DB;
        // Contexto del curso
        $this->context = $context;
        // Recuperamos los usuarios con roles en este contexto
        $roles = $DB->get_records('role');
        foreach ($roles as $rol) {
            $this->roles[$rol->shortname] = $rol->id;
        }
    }

    /**
     * Chequea si un usuario tiene un rol específico en este curso
     * @param string $rolshortname Shortname del rol a chequear
     * @param int $userid Id del usuario
     * @return bool
     */
    public function is(string $rolshortname, int $userid): bool {
        if (!isset($this->roles[$rolshortname])) {
            return false; // rol no existe
        }
        $roleid = $this->roles[$rolshortname];
        // Obtener los roles del usuario en este contexto
        $userroles = get_user_roles($this->context, $userid);
        // Verificar si el rol existe en el array de roles del usuario
        $filtered = array_filter($userroles, fn($ra) => $ra->shortname === $rolshortname);
        return count($filtered) > 0;
    }
}
