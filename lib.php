<?php
defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\PluginInstanceModel;
use mod_pluginpatroller\service\core\GradebookService;

/**
 * Crea una nueva instancia del plugin.
 *
 * @param object $pluginpatroller
 * @return int
 */
function pluginpatroller_add_instance($pluginpatroller): int {
    $id = PluginInstanceModel::create($pluginpatroller);
    $pluginpatroller->id = $id;

    GradebookService::createOrUpdateGradeItem($pluginpatroller);
    return $id;
}

/**
 * Actualiza una instancia existente del plugin.
 *
 * @param object $pluginpatroller
 * @return bool
 */
function pluginpatroller_update_instance($pluginpatroller): bool {
    $pluginpatroller->id = $pluginpatroller->instance;

    GradebookService::createOrUpdateGradeItem($pluginpatroller);
    return PluginInstanceModel::update($pluginpatroller);
}

/**
 * Elimina una instancia del plugin.
 *
 * @param int $id
 * @return bool
 */
function pluginpatroller_delete_instance($id): bool {
    return PluginInstanceModel::delete($id);
}

/**
 * Extiende la navegación de configuración del módulo (si se desea).
 */
function pluginpatroller_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $pluginpatrollernode): void {
    global $PAGE;
    // Aquí podrías agregar enlaces personalizados si lo necesitás.
}

/**
 * Crea o actualiza el ítem de calificación en el Gradebook.
 *
 * @param object $pluginpatroller
 * @param int $maxgrade
 */
function pluginpatroller_grade_item_update($pluginpatroller, $maxgrade = 10): void {
    GradebookService::createOrUpdateGradeItem($pluginpatroller, $maxgrade);
}
