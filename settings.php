<?php

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    // Campo para token_patroller
    $settings->add(new admin_setting_configtext(
        'pluginpatroller/token_patroller',
        get_string('tokenpatroller', 'pluginpatroller'),
        get_string('tokenpatroller_desc', 'pluginpatroller'),
        '', // Token debe ser configurado por cada usuario
        PARAM_TEXT
    ));

    // Campo para owner_patroller
    $settings->add(new admin_setting_configtext(
        'pluginpatroller/owner_patroller',
        get_string('ownerpatroller', 'pluginpatroller'),
        get_string('ownerpatroller_desc', 'pluginpatroller'),
        '', // Organización debe ser configurada por cada usuario
        PARAM_TEXT
    ));

    // Sección de IA
    $settings->add(new admin_setting_heading(
        'pluginpatroller/ai_heading',
        get_string('ai_settings', 'pluginpatroller'),
        get_string('ai_settings_desc', 'pluginpatroller')
    ));

    // Campo para API key de Google Gemini
    $settings->add(new admin_setting_configpasswordunmask(
        'pluginpatroller/gemini_api_key',
        get_string('gemini_api_key', 'pluginpatroller'),
        get_string('gemini_api_key_desc', 'pluginpatroller'),
        '', // API key debe ser configurada por cada usuario
        PARAM_TEXT
    ));
}
?>