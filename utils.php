<?php
/**
 * ===== File: utils.php =====
 */
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
require_once('../../config.php');
require_once('lib.php');

defined('MOODLE_INTERNAL') || die();


// get_students_by_repoid_and_course_id->
//a refactorizar a model/UserModel.php o model/RepositoryModel.php
//segun corresponda y donde se utilice. 
function get_students_by_repoid_and_course_id($repo_id, $course_id, $solo_activos = false)
{
    global $DB;

    // Obtiene los registros de estudiantes que tienen asignado este ID de repositorio
    // y este ID de materia.
    if ($solo_activos) {
        $resultado = $DB->get_records('usuarios_data_patroller', [ 'id_repo' => $repo_id, 'id_materia' => $course_id, 'invitacion_status' => 5]);
    } else {
        $resultado = $DB->get_records('usuarios_data_patroller', [ 'id_repo' => $repo_id, 'id_materia' => $course_id ]);
    }

    // Si no encuentra registros, get_records devuelve un array vacío.
    // get_records nunca devuelve `false`, así que esta verificación es innecesaria,
    // pero no causa problemas.
    if ($resultado === false) {
        $resultado = [];
    }
    
    return $resultado;
}

function filter_sede_curso($course)
{
    // TODO - Buscar la manera de obtener las sedes sin machetearlas.
    $options_sede = array( '' => get_string('all', 'moodle'), 'YA' => 'YA', 'BE' => 'BE' );
    $options_curso = array_merge(get_all_cursos_by_course_id($course->id), ["" => "All"]);

    echo '<div style="margin:15px">';
    echo '<table><tr>';
    echo '<th style="border:none; padding:.5em;"><label for="filterSede" style="margin-right: 1em;">' . get_string('filterbysede', 'pluginpatroller') . ':</label></th>';
    echo '<td style="border:none; padding:.5em;">'. html_writer::select($options_sede, 'filterSede', '', null, array('id' => 'filterSede', 'onchange' => 'filterTable()', 'style' => 'margin-right: inherit;')) . '</td>';
    echo '<th style="border:none; padding:.5em;"><label for="filterCurso" style="margin-right: 1em;">' . get_string('filterbycurso', 'pluginpatroller') . ':</label></th>';
    echo '<td style="border:none; padding:.5em;">'. html_writer::select($options_curso, 'filterCurso', '', null, array('id' => 'filterCurso', 'onchange' => 'filterTable()', 'style' => 'margin-right: inherit;')) . '</td>';
    echo '</tr></table>';
    echo '</div>';

    echo '<script>
        function filterTable() {
            var sedeFilter = document.getElementById("filterSede").value.toUpperCase();
            var cursoFilter = document.getElementById("filterCurso").value.toUpperCase();
            var table = document.getElementById("userTable");
            var tr = table.getElementsByTagName("tr");

            for (var i = 1; i < tr.length; i++) {
                var tdSede = tr[i].getElementsByTagName("td")[0];
                var tdCurso = tr[i].getElementsByTagName("td")[1];
                if (tdSede && tdCurso) {
                    var sedeValue = tdSede.textContent || tdSede.innerText;
                    var cursoValue = tdCurso.textContent || tdCurso.innerText;
                    if ((sedeFilter === "" || sedeValue.toUpperCase() === sedeFilter) &&
                        (cursoFilter === "" || cursoValue.toUpperCase() === cursoFilter)) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }
    </script>';
}

function get_all_contributions_snapshot(int $course_id): array {
    $owner = get_config('pluginpatroller', 'owner_patroller');
    $token = get_config('pluginpatroller', 'token_patroller');

    $repo_list = get_all_repositories_by_course_id($course_id);
    $snapshot = [];

    // Parámetros de paginación/seguridad para no volar la cuota.
    $perPage   = 100;  // máximo permitido por la Search API
    $maxPages  = 10;   // hasta 1000 resultados por repo (límite de la Search API)
    $timeout   = 30;   // segundos de timeout por request
	
	$fecha_segura = date('Y-m-d\TH:i:s\Z', strtotime('-1 year'));

	foreach ($repo_list as $repo_id => $repo_name) {

        $contributors = [];
        $page = 1;

        while ($page <= $maxPages) {
            // IMPORTANTE: Search commits requiere header "cloak-preview".
            // Usamos parámetros sort/order en querystring (más confiable que meterlos en q=).
            $q   = 'repo:' . $owner . '/' . $repo_name;
            $url = 'https://api.github.com/search/commits'
                 . '?q=' . rawurlencode($q) . '+author-date:>=' . $fecha_segura
                 . '&sort=author-date'
                 . '&order=desc'
                 . '&per_page=' . $perPage
                 . '&page=' . $page;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/vnd.github.cloak-preview+json',
                    'Authorization: Bearer ' . $token,
                    'User-Agent: GitHub-API-Request',
                ],
            ]);

            $resp     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = curl_error($ch);
            curl_close($ch);

            if ($err) {
                // Para diagnóstico rápido en pantalla sin romper flujo:
                echo "Error SearchAPI ($repo_name, page $page): $err<br>";
                break;
            }
            if ($httpCode !== 200) {
                // 403 rate limit / 422 query inválida, etc.
                echo "HTTP $httpCode en SearchAPI ($repo_name, page $page).<br>";
                // Si hay error, salimos del repo para no spamear.
                break;
            }

            $data  = json_decode($resp, true);
            $items = $data['items'] ?? [];

            if (empty($items)) {
                // No hay más resultados
                break;
            }

            // Recorremos commits y pedimos detalle para stats.
            foreach ($items as $commit) {
                // author puede venir null si el commit no está asociado a un usuario de GitHub.
                $login = $commit['author']['login'] ?? null;
                if (!$login) {
                    // Mantener consistencia con tu función original: los saltamos.
                    continue;
                }

                if (!isset($contributors[$login])) {
                    $contributors[$login] = [
                        'last_commit'    => '',
                        'total_commits'  => 0,
                        'total_added'    => 0,
                        'total_deleted'  => 0,
                        'total_modified' => 0,
                    ];
                }

                // Fecha último commit (tomamos la más reciente).
                $dateIso = $commit['commit']['author']['date'] ?? null;
                if ($dateIso) {
                    $dateTrim = explode('.', $dateIso)[0]; // igual que tu función original
                    if ($contributors[$login]['last_commit'] === '' ||
                        $dateTrim > $contributors[$login]['last_commit']) {
                        $contributors[$login]['last_commit'] = $dateTrim;
                    }
                }

                // Stats por commit (additions/deletions/total) -> pedir detalle.
                $sha = $commit['sha'];
                $detailUrl = 'https://api.github.com/repos/'
                    . rawurlencode($owner) . '/'
                    . rawurlencode($repo_name) . '/commits/' . $sha;

                $chd = curl_init($detailUrl);
                curl_setopt_array($chd, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_HTTPHEADER     => [
                        'Accept: application/vnd.github+json',
                        'Authorization: Bearer ' . $token,
                        'User-Agent: GitHub-API-Request',
                    ],
                ]);
                $detailResp  = curl_exec($chd);
                $detailCode  = curl_getinfo($chd, CURLINFO_HTTP_CODE);
                $detailError = curl_error($chd);
                curl_close($chd);

                if ($detailError) {
                    echo "Error CommitDetail ($repo_name:$sha): $detailError<br>";
                    continue;
                }
                if ($detailCode !== 200) {
                    echo "HTTP $detailCode en CommitDetail ($repo_name:$sha).<br>";
                    continue;
                }

                $detail = json_decode($detailResp, true);
                if (isset($detail['stats'])) {
                    $contributors[$login]['total_commits']  += 1;
                    $contributors[$login]['total_added']    += (int)$detail['stats']['additions'];
                    $contributors[$login]['total_deleted']  += (int)$detail['stats']['deletions'];
                    $contributors[$login]['total_modified'] += (int)$detail['stats']['total'];
                } else {
                    // Si no trae 'stats', al menos contemos el commit.
                    $contributors[$login]['total_commits']  += 1;
                }
            }

            // Si la página vino "incompleta", ya no hay más
            if (count($items) < $perPage) {
                break;
            }

            $page++;
        }

        // Guardamos por NOMBRE de repo (útil para comparar a ojo)
        $snapshot[$repo_name] = $contributors;
    }

    return $snapshot;
}

function render_contributions_snapshot_table(int $course_id): void {
    $snapshot = get_all_contributions_snapshot($course_id);

    echo '<table class="generaltable" id="contribPreview" style="margin-top:1rem">';
    echo '<thead><tr>'
       . '<th>Repositorio</th>'
       . '<th>Usuario</th>'
       . '<th>Último commit</th>'
       . '<th>Commits</th>'
       . '<th>Líneas +</th>'
       . '<th>Líneas -</th>'
       . '<th>Total modif</th>'
       . '</tr></thead><tbody>';

    foreach ($snapshot as $repoName => $users) {
        if (empty($users)) {
            echo '<tr>'
               . '<td>' . htmlspecialchars($repoName) . '</td>'
               . '<td colspan="6" style="color:#777">— sin datos —</td>'
               . '</tr>';
            continue;
        }
        foreach ($users as $login => $info) {
            echo '<tr>'
               . '<td>' . htmlspecialchars($repoName) . '</td>'
               . '<td>' . htmlspecialchars($login) . '</td>'
               . '<td>' . htmlspecialchars($info['last_commit']) . '</td>'
               . '<td>' . (int)$info['total_commits'] . '</td>'
               . '<td>' . (int)$info['total_added'] . '</td>'
               . '<td>' . (int)$info['total_deleted'] . '</td>'
               . '<td>' . (int)$info['total_modified'] . '</td>'
               . '</tr>';
        }
    }

    echo '</tbody></table>';
}