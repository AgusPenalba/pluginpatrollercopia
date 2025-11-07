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
use mod_pluginpatroller\controllers\AIInsightsController;
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
// No poner 'tab1' como valor por defecto aquí: dejamos vacío para que
// el valor por defecto real se asigne más abajo en función del rol
// (por ejemplo los alumnos deben llegar a 'tab2' por defecto).
$tab = optional_param('tab', '', PARAM_TEXT);

// Si es POST, procesarlo ANTES de renderizar el header
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = optional_param('action', '', PARAM_ALPHA);
    
                // Procesar según la tab activa
                switch ($tab) {
                    case 'tab6': // Repositorios Profesor
                        if (!RoleHelper::isStudent($USER->id, $context)) {
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
                        break;        // Agregar otros casos si hay más controladores que procesen POST
        // case 'tab1':
        //     ...
        //     break;
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
        $tabrows[] = new tabobject('tab2', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab2')), 'Participantes');
        // Mostrar 'Mi Grupo' sólo si el usuario tiene un repositorio asignado para esta materia
        if (!empty($userRepo)) {
            $tabrows[] = new tabobject('tab5', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab5')), 'Mi Grupo');
           
        }
        $tabDefault = 'tab2';
    }
} else {
    $tabrows[] = new tabobject('tab1', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab1')), 'Repositorios');
    if ($data) {
        $tabrows[] = new tabobject('tab2', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab2')), 'Gestión de accesos');
        $tabrows[] = new tabobject('tab3', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab3')), 'Seguimiento y calificación');
        // Nueva pestaña para estadísticas (admin)
        $tabrows[] = new tabobject('tab_stats', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab_stats')), 'Estadísticas');
        // Nueva pestaña para IA
        $tabrows[] = new tabobject('tab_ai', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab_ai')), 'IA Insights');
    }
    $tabrows[] = new tabobject('tab4', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab4')), 'Sin registrar');
    $tabrows[] = new tabobject('tab6', new moodle_url('/mod/pluginpatroller/view.php', array('id' => $id, 'tab' => 'tab6')), 'Repositorios Profesor');
}

// Verificar valor del parámetro 'tab'
if (empty($tab)) {
    $tab = $tabDefault;
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
        case 'tab_ai':
            if ($data && !$is_student) {
                $controller = new AIInsightsController($context, $course, $cm, $pluginpatroller);
                echo $controller->execute();
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
                // Solo renderizar (POST ya fue procesado arriba)
                echo $controller->executeView();
            }
            break;
        default:
            echo "<p>Pestaña desconocida.</p>";
    }
} catch (Exception $e) {
    echo $OUTPUT->notification('Ha ocurrido un error: ' . $e->getMessage(), 'error');
}

echo "<hr>";
echo $OUTPUT->footer();