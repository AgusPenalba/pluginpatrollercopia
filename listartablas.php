<?php

require_once('../../config.php');



// Declara la variable global de la base de datos
global $DB;

// ===============================================================
// RESETEAR ASIGNACIONES HUÉRFANAS
// ===============================================================
if (isset($_GET['reset_orphaned'])) {
    echo "<div style='background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin: 10px 0;'>";
    echo "<h3>🔄 Reseteando asignaciones huérfanas...</h3>";
    
    // Obtener todos los estudiantes con id_repo asignado
    $students_with_repos = $DB->get_records_sql(
        "SELECT id, id_usuario, id_repo FROM {usuarios_data_patroller} WHERE id_repo IS NOT NULL AND id_repo > 0"
    );
    
    $reset_count = 0;
    foreach ($students_with_repos as $student) {
        // Verificar si el repositorio existe
        $repo_exists = $DB->record_exists('repositorios_data_patroller', ['id' => $student->id_repo]);
        
        if (!$repo_exists) {
            // El repo no existe, resetear asignación
            $DB->set_field('usuarios_data_patroller', 'id_repo', null, ['id' => $student->id]);
            $reset_count++;
            echo "<p>✅ Usuario ID {$student->id_usuario}: repo {$student->id_repo} eliminado - asignación reseteada</p>";
        }
    }
    
    if ($reset_count === 0) {
        echo "<p>ℹ️ No se encontraron asignaciones huérfanas. Todo está sincronizado.</p>";
    } else {
        echo "<p><strong>✅ Total reseteados: {$reset_count}</strong></p>";
    }
    
    echo "<p><a href='listartablas.php' style='background: blue; color: white; padding: 8px 15px; text-decoration: none;'>Ver tablas actualizadas</a></p>";
    echo "</div>";
}

// ===============================================================
// SINCRONIZAR GRUPOS DE MOODLE (DEBUG)
// ===============================================================
if (isset($_GET['sync_groups'])) {
    echo "<div style='background: #d1ecf1; border: 2px solid #0c5460; padding: 15px; margin: 10px 0;'>";
    echo "<h3>🔍 Debug: Análisis de grupos y conteo</h3>";
    
    require_once($CFG->dirroot.'/group/lib.php');
    require_once(__DIR__ . '/classes/service/student/StudentGroupService.php');
    
    $course_id = 2;
    $context = context_course::instance($course_id);
    $course = $DB->get_record('course', ['id' => $course_id]);
    $cm = get_coursemodule_from_instance('pluginpatroller', 1, $course_id);
    
    $service = new \mod_pluginpatroller\service\student\StudentGroupService($context, $course, $cm);
    $users_by_group = $service->getStudentsByCourseGroup();
    
    echo "<h4>Grupos encontrados:</h4>";
    foreach ($users_by_group as $group_key => $group_data) {
        $student_count = count($group_data['students']);
        echo "<p><strong>{$group_key}</strong>: {$student_count} estudiantes</p>";
        foreach ($group_data['students'] as $student) {
            echo "<p style='margin-left: 20px;'>- Usuario ID: {$student['id_usuario']}, Nombre: {$student['nombre']}</p>";
        }
    }
    
    // Ver repos
    $users_by_repo = [];
    $repos = $DB->get_records('repositorios_data_patroller', ['id_materia' => $course_id]);
    foreach ($repos as $repo) {
        $students_in_repo = $DB->get_records('usuarios_data_patroller', ['id_repo' => $repo->id, 'id_materia' => $course_id]);
        $users_by_repo[$repo->id] = [
            'sede' => $repo->sede,
            'curso' => $repo->curso,
            'students' => $students_in_repo
        ];
    }
    
    echo "<h4>Repositorios creados: " . count($repos) . "</h4>";
    
    $total_pending = $service->getTotalPendingStudents($users_by_group, $users_by_repo);
    echo "<h4>Total pendientes calculado: {$total_pending}</h4>";
    
    echo "<p><a href='listartablas.php' style='background: blue; color: white; padding: 8px 15px; text-decoration: none;'>Volver</a></p>";
    echo "</div>";
}

echo "<h1>Contenido de las Tablas</h1>";

// ===============================================================
// LISTAR CONTENIDO DE usuarios_data_patroller
// ===============================================================
echo "<h2>Tabla: usuarios_data_patroller</h2>";
echo "<pre>";

try {
     if (isset($_GET['clear'])) {
        $data = new stdClass();
        $data->id = 1;
        $data->cantidad_commits = 0;
        $data->lineas_agregadas = 0;
        $data->lineas_eliminadas = 0;
        $data->lineas_modificadas = 0;
        $data->fecha_ultimo_commit = null; // O una cadena vacía, dependiendo de la configuración de la columna
        $data->invitacion_status = 0; // Estado 'sin procesar'
        $DB->update_record('usuarios_data_patroller', $data);
     }
    // $DB->delete_records('usuarios_data_patroller', [ 'sede' => 'BE', 'curso' => 'B']);
    // $DB->delete_records('repositorios_data_patroller', [ 'sede' => 'BE', 'curso' => 'B']);

    // $DB->set_field('usuarios_data_patroller', 'cantidad_commits', 0, ['id' => 1]);
    // $DB->set_field('usuarios_data_patroller', 'lineas_agregadas', 0, ['id' => 1]);
    // $DB->set_field('usuarios_data_patroller', 'lineas_eliminadas', 0, ['id' => 1]);
    // $DB->set_field('usuarios_data_patroller', 'lineas_modificadas', 0, ['id' => 1]);
    // $DB->set_field('usuarios_data_patroller', 'fecha_ultimo_commit', '', ['id' => 1]);

    // Obtiene todos los registros de la tabla de alumnos
    $alumnos = $DB->get_records('usuarios_data_patroller');

    if (empty($alumnos)) {
        echo "La tabla 'usuarios_data_patroller' está vacía.";
    } else {
        // Muestra los registros de forma legible
        echo "<h3>Usuarios en la plataforma:</h3>";
        echo "<p><strong>Total de registros: " . count($alumnos) . "</strong></p>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>ID Usuario</th><th>GitHub</th><th>Materia</th><th>ID Repo</th><th>Sede</th><th>Curso</th></tr>";
        foreach ($alumnos as $alumno) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($alumno->id) . "</td>";
            echo "<td>" . htmlspecialchars($alumno->id_usuario) . "</td>";
            echo "<td>" . htmlspecialchars($alumno->usuario_github ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($alumno->id_materia) . "</td>";
            echo "<td>" . htmlspecialchars($alumno->id_repo ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($alumno->sede ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($alumno->curso ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "Ocurrió un error al intentar leer la tabla de alumnos: " . $e->getMessage();
}

echo "</pre>";

// ===============================================================
// LISTAR CONTENIDO DE repositorios_data_patroller
// ===============================================================
echo "<h2>Tabla: repositorios_data_patroller</h2>";
echo "<pre>";

try {
    // Obtiene todos los registros de la tabla de repositorios
    $repos = $DB->get_records('repositorios_data_patroller');
    
    if (empty($repos)) {
        echo "La tabla 'repositorios_data_patroller' está vacía.";
    } else {
        echo "<h3>Repositorios configurados:</h3>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Nombre</th><th>Materia</th><th>Fecha Creación</th></tr>";
        foreach ($repos as $repo) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($repo->id) . "</td>";
            echo "<td>" . htmlspecialchars($repo->nombre_repo) . "</td>";
            echo "<td>" . htmlspecialchars($repo->id_materia) . "</td>";
            echo "<td>" . htmlspecialchars($repo->fecha_creacion ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "Ocurrió un error al intentar leer la tabla de repositorios: " . $e->getMessage();
}

echo "</pre>";

// ===============================================================
// LISTAR CONTENIDO DE repos_profesores (NUEVO)
// ===============================================================
echo "<h2>Tabla: repos_profesores</h2>";
echo "<pre>";

try {
    // Verificar existencia de la tabla según prefijo   
    $prefix = $DB->get_prefix();
    $fulltable = $prefix . 'repos_profesores';
    $exists = $DB->get_records_sql('SHOW TABLES LIKE ?', [$fulltable]); 

    if (empty($exists)) {
        echo "La tabla 'repos_profesores' (" . htmlspecialchars($fulltable) . ") no fue encontrada en la base de datos.";
    } else {
        // Muestra hasta 10 registros de ejemplo
        $assoc = $DB->get_records('repos_profesores', null, '', '*', 0, 10);
        if (empty($assoc)) {
            echo "La tabla 'repos_profesores' existe pero está vacía.";
        } else {
            echo "<h3>Repositorios de profesores (últimos 10):</h3>";
            echo "<table border='1'>";
            echo "<tr><th>ID</th><th>Usuario ID</th><th>Repositorio</th><th>Fecha Creación</th></tr>";
            foreach ($assoc as $item) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($item->id ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($item->user_id ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($item->repository_name ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($item->created_at ?? 'N/A') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        $count = $DB->count_records('repos_profesores');
        echo "<p>Total de registros en repos_profesores: " . intval($count) . "</p>";
    }
} catch (Exception $e) {
    echo "Ocurrió un error al intentar leer la tabla repos_profesores: " . $e->getMessage();
}

echo "</pre>";

// ===============================================================
// LISTAR CONTENIDO DE repos_profesores (NUEVO)
// ===============================================================
echo "<hr>";
echo "<h2>🔧 Acciones Disponibles</h2>";
echo "<p><a href='listartablas.php?clear=1' style='background: orange; color: white; padding: 10px; text-decoration: none; margin: 5px;'>🧹 LIMPIAR DATOS DE USUARIO 1</a></p>";
echo "<p><a href='listartablas.php?reset_orphaned=1' style='background: red; color: white; padding: 10px; text-decoration: none; margin: 5px;' onclick='return confirm(\"¿Resetear asignaciones huérfanas (estudiantes con repos eliminados)?\");'>🔄 RESETEAR ASIGNACIONES HUÉRFANAS</a></p>";
echo "<p><a href='listartablas.php?sync_groups=1' style='background: green; color: white; padding: 10px; text-decoration: none; margin: 5px;' onclick='return confirm(\"¿Sincronizar grupos de Moodle a usuarios_data_patroller?\");'>🔄 SINCRONIZAR GRUPOS DE MOODLE</a></p>";
echo "<p><a href='view.php?id=2&tab=tab2' style='background: blue; color: white; padding: 10px; text-decoration: none; margin: 5px;'>🔙 VOLVER A GESTIÓN DE ACCESOS</a></p>";



?>