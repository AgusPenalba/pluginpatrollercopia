<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Archivo de actualización para el plugin patroller
 * 
 * @package   mod_pluginpatroller
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Función de actualización del plugin
 * 
 * @param int $oldversion Versión anterior del plugin
 * @return bool true si la actualización es exitosa
 */
function xmldb_pluginpatroller_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Actualización a la versión 2025101002
    if ($oldversion < 2025101002) {
        
        // Definir tabla repos_profesores
        $table = new xmldb_table('repos_profesores');

        // Verificar si la tabla ya existe
        if (!$dbman->table_exists($table)) {
            
            // Añadir campos
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('repo_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('github_username', XMLDB_TYPE_CHAR, '191', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('invitacion_status', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('invitacion_status_updated', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            
            // Añadir claves
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('unique_repo_user', XMLDB_KEY_UNIQUE, ['repo_id', 'userid']);
            $table->add_key('fk_repo', XMLDB_KEY_FOREIGN, ['repo_id'], 'repositorios_data_patroller', ['id']);
            $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            // Crear tabla
            $dbman->create_table($table);
        }
        
        // Registro del punto de actualización completado
        upgrade_mod_savepoint(true, 2025101002, 'pluginpatroller');
    }

    return true;
}