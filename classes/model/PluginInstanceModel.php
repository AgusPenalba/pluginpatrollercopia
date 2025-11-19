<?php
namespace mod_pluginpatroller\model;

defined('MOODLE_INTERNAL') || die();

class PluginInstanceModel {

    /**
     * Crea una nueva instancia del plugin.
     *
     * @param object $data Datos de la instancia (name, course, etc.)
     * @return int ID de la nueva instancia
     */
    public static function create(object $data): int {
        global $DB;

        $data->timecreated = time();
        $data->timemodified = time();

        return $DB->insert_record('pluginpatroller', $data);
    }

    /**
     * Actualiza una instancia existente del plugin.
     *
     * @param object $data Datos actualizados (debe incluir 'id')
     * @return bool
     */
    public static function update(object $data): bool {
        global $DB;

        $data->timemodified = time();
        return $DB->update_record('pluginpatroller', $data);
    }

    /**
     * Elimina una instancia del plugin.
     *
     * @param int $id ID de la instancia
     * @return bool
     */
    public static function delete(int $id): bool {
        global $DB;

        return $DB->delete_records('pluginpatroller', ['id' => $id]);
    }

    /**
     * Obtiene una instancia por su ID.
     *
     * @param int $id
     * @return object|null
     */
    public static function getById(int $id): ?object {
        global $DB;

        return $DB->get_record('pluginpatroller', ['id' => $id]);
    }
}
