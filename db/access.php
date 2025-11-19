<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Capacidad para agregar instancias del módulo en un curso
    'mod/pluginpatroller:addinstance' => [
        'captype' => 'write',
        'contextlevel' => 50, // CONTEXT_COURSE
        'archetypes' => [
            'editingteacher' => 1, // CAP_ALLOW
            'manager' => 1 // CAP_ALLOW
        ],
        'clonepermissionsfrom' => 'moodle/course:manageactivities'
    ],
    'mod/pluginpatroller:view' => [
        'captype' => 'read',                   // Tipo: lectura
        'contextlevel' => 70,                  // CONTEXT_MODULE
        'archetypes'=> [
            'guest' => 1,          // CAP_ALLOW
            'student' => 1,        // CAP_ALLOW
            'teacher' => 1,        // CAP_ALLOW
            'editingteacher' => 1, // CAP_ALLOW
            'manager' => 1         // CAP_ALLOW
        ],
    ],
    
    // Capacidad para gestionar datos del módulo (actualizar commits, calificar, etc.)
    'mod/pluginpatroller:manage' => [
        'captype' => 'write',
        'contextlevel' => 70,                  // CONTEXT_MODULE
        'archetypes' => [
            'teacher' => 1,        // CAP_ALLOW
            'editingteacher' => 1, // CAP_ALLOW
            'manager' => 1         // CAP_ALLOW
        ],
        'clonepermissionsfrom' => 'moodle/grade:edit'
    ],
];
