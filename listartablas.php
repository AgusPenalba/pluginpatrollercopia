<?php

require_once('../../config.php');



// Declara la variable global de la base de datos
global $DB;

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
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Usuario</th><th>GitHub</th><th>Materia</th><th>Repositorio</th></tr>";
        foreach ($alumnos as $alumno) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($alumno->id) . "</td>";
            echo "<td>" . htmlspecialchars($alumno->id_usuario) . "</td>";
            echo "<td>" . htmlspecialchars($alumno->usuario_github ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($alumno->id_materia) . "</td>";
            echo "<td>" . htmlspecialchars($alumno->repositorio_asignado ?? 'N/A') . "</td>";
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
echo "<p><a href='view.php?id=2&tab=tab2' style='background: blue; color: white; padding: 10px; text-decoration: none; margin: 5px;'>🔙 VOLVER A GESTIÓN DE ACCESOS</a></p>";



?>