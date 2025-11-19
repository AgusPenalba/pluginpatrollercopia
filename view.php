<?php

global $CFG;
require('../../config.php');
require_once($CFG->dirroot.'/lib/accesslib.php');
require_once($CFG->dirroot.'/lib/weblib.php');
require_once($CFG->dirroot.'/lib/moodlelib.php');
require_once($CFG->dirroot.'/lib/navigationlib.php');
require_once('lib.php');

// Autoload de clases del plugin
spl_autoload_register(function ($class) {
    if (strpos($class, 'mod_pluginpatroller\\') === 0) {
        $path = __DIR__ . '/classes/' . str_replace(['mod_pluginpatroller\\', '\\'], ['', '/'], $class) . '.php';
        if (file_exists($path)) {
            require_once($path);
        }
    }
});

// Importar los controladores y clases necesarias
use mod_pluginpatroller\controllers\MainPanelController;
use mod_pluginpatroller\controllers\GestionAccesosController;
use mod_pluginpatroller\controllers\ContributorsController;
use mod_pluginpatroller\controllers\StudentViewController;
use mod_pluginpatroller\controllers\GroupController;
use mod_pluginpatroller\controllers\TeacherReposController;
use mod_pluginpatroller\helpers\RoleHelper;

global $DB, $OUTPUT, $PAGE, $USER;

// Configurar la página
$id = required_param('id', PARAM_INT);

// Obtener el módulo del curso (cm), el curso, y la instancia del plugin
if ($id) {
    $cm = get_coursemodule_from_id('pluginpatroller', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $pluginpatroller = $DB->get_record('pluginpatroller', array('id' => $cm->instance), '*', MUST_EXIST);
} else {
    print_error('Course module ID is required.');
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

// Verificar capacidad de ver el módulo
if (!has_capability('mod/pluginpatroller:view', $context)) {
    throw new required_capability_exception($context, 'mod/pluginpatroller:view', 'nopermissions', '');
}

// ===== IMPORTANTE: PROCESAR POST ANTES DEL HEADER =====
$tab = optional_param('tab', 'tab1', PARAM_TEXT);

// Si es POST, procesarlo ANTES DE renderizar header
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = optional_param('action', '', PARAM_ALPHA);
    
    // Solo procesar tab6 (TeacherRepos)
    if ($tab === 'tab6' && !RoleHelper::isStudent($USER->id, $context)) {
        require_once(__DIR__ . '/classes/controllers/TeacherReposController.php');
        $controller = new TeacherReposController($context, $course, $cm, $pluginpatroller);
        
        // Ejecutar solo el procesamiento POST (sin renderizar)
        $controller->handlePostRequest();
        
        // Redirigir después de procesar
        $redirect_url = new moodle_url('/mod/pluginpatroller/view.php', [
            'id' => $cm->id,
            'tab' => $tab
        ]);
        redirect($redirect_url);
        exit;
    }
}

// ===== AHORA SÍ RENDERIZAR EL HEADER (después de procesar POST) =====

$PAGE->requires->css('/mod/pluginpatroller/css/style.css');
$PAGE->set_url('/mod/pluginpatroller/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($pluginpatroller->name));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();

// Usar RoleHelper centralizado
$is_student = RoleHelper::isStudent($USER->id, $context);
$data = $DB->get_records('repositorios_data_patroller', array('id_materia' => $course->id));

// Comprobar si el usuario tiene un repo asignado en esta materia
$userRepo = $DB->get_field('usuarios_data_patroller', 'id_repo', ['id_usuario' => $USER->id, 'id_materia' => $course->id]);

// Definir las pestañas
$tabrows = array();
$tabDefault = 'tab1';

if ($is_student) {
    if ($data) {
        $tabrows[] = new tabobject('tab2', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab2')), get_string('tabparticipants', 'mod_pluginpatroller'));
        // Mostrar 'Mi Grupo' sólo si el usuario tiene un repositorio asignado para esta materia
        if (!empty($userRepo)) {
            $tabrows[] = new tabobject('tab5', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab5')), get_string('tabmygroup', 'mod_pluginpatroller'));
           
        }
        $tabDefault = 'tab2';
    }
} else {
    $tabrows[] = new tabobject('tab1', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab1')), get_string('tabrepositories', 'mod_pluginpatroller'));
    if ($data) {
        $tabrows[] = new tabobject('tab2', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab2')), get_string('tabaccessmanagement', 'mod_pluginpatroller'));
        $tabrows[] = new tabobject('tab3', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab3')), get_string('tabtrackinggrading', 'mod_pluginpatroller'));
        $tabrows[] = new tabobject('tab_stats', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab_stats')), get_string('tabstatistics', 'mod_pluginpatroller'));
    }
    $tabrows[] = new tabobject('tab4', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab4')), get_string('tabunregistered', 'mod_pluginpatroller'));
    $tabrows[] = new tabobject('tab6', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab6')), get_string('tabteacherrepos', 'mod_pluginpatroller'));
}

// Verificar valor del parámetro 'tab'
if (empty($tab)) {
    $tab = $tabDefault;
}

// Auto-redirigir estudiantes al primer tab disponible si acceden sin tab específico
if ($is_student && !isset($_GET['tab']) && $data) {
    $redirect_url = new moodle_url('/mod/pluginpatroller/view.php', [
        'id' => $cm->id,
        'tab' => 'tab2'
    ]);
    redirect($redirect_url);
    exit;
}

print_tabs(array($tabrows), $tab);

// Contenido según la pestaña activa usando los controladores MVC
try {
    switch ($tab) {
        case 'tab1':
            if (!$is_student) {
                $controller = new MainPanelController($context, $course, $cm, $pluginpatroller);
                echo $controller->execute();
            }
            break;
        case 'tab2':
            if ($data) {
                if ($is_student) {
                    $controller = new StudentViewController($context, $course, $cm, $pluginpatroller);
                    echo $controller->execute();
                } else {
                    $controller = new GestionAccesosController($context, $course, $cm, $pluginpatroller);
                    echo $controller->execute();
                }
            }
            break;
        case 'tab3':
            if ($data && !$is_student) {
                $controller = new ContributorsController($context, $course, $cm, $pluginpatroller);
                echo $controller->execute();
            }
            break;
        case 'tab_stats':
            if ($data && !$is_student) {
                $controller = new MainPanelController($context, $course, $cm, $pluginpatroller);
                echo $controller->executeStatistics();
            }
            break;
        case 'tab4':
            if (!$is_student) {
                // Tab4 muestra estudiantes sin registrar
                $controller = new StudentViewController($context, $course, $cm, $pluginpatroller);
                echo $controller->execute();
            }
            break;
        case 'tab5':
            if ($is_student) {
                $controller = new GroupController($context, $course, $cm, $pluginpatroller);
                echo $controller->execute();
            }
            break;
        case 'tab6':
            if (!$is_student) {
                $controller = new TeacherReposController($context, $course, $cm, $pluginpatroller);
                echo $controller->execute();
            }
            break;
        default:
            echo "<p>" . get_string('unknowntab', 'mod_pluginpatroller') . "</p>";
    }
} catch (Exception $e) {
    echo $OUTPUT->notification(get_string('erroroccurred', 'mod_pluginpatroller') . ': ' . $e->getMessage(), 'error');
    error_log('Error en view.php: ' . $e->getMessage());
}

echo "<hr>";
echo $OUTPUT->footer();