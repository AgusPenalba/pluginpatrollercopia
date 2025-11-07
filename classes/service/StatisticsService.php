<?php
namespace mod_pluginpatroller\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Servicio centralizado para cálculos de estadísticas reutilizables.
 * Provee funciones para calcular métricas a partir de arrays de students
 * y para generar comparativas entre repositorios/grupos.
 */
class StatisticsService {

    /**
     * Calcula estadísticas agregadas a partir de un array de filas de estudiante.
     * Entrada esperada: cada elemento debe contener 'commit_count' y opcionalmente otras métricas.
     * Retorna un array con keys: total_students, total_commits, avg_commits, participation_rate,
     * students_with_commits, students_without_commits
     */
    public function calculateFromStudents(array $students_data): array {
        $total_students = count($students_data);
        $total_commits = 0;
        $with_commits = 0;

        foreach ($students_data as $s) {
            $commits = isset($s['commit_count']) ? (int)$s['commit_count'] : 0;
            $total_commits += $commits;
            if ($commits > 0) $with_commits++;
        }

        $avg_commits = $total_students > 0 ? round($total_commits / $total_students, 2) : 0;
        $participation_rate = $total_students > 0 ? round(($with_commits / $total_students) * 100, 1) : 0;

        return [
            'total_students' => $total_students,
            'total_commits' => $total_commits,
            'avg_commits' => $avg_commits,
            'participation_rate' => $participation_rate,
            'students_with_commits' => $with_commits,
            'students_without_commits' => $total_students - $with_commits,
        ];
    }

    /**
     * Genera estadísticas por repositorio/grupo dado un array de repos con 'students'.
     * Retorna un array indexado por repo_id con métricas y datos básicos del repo.
     */
    public function calculatePerRepo(array $users_by_repo): array {
        $result = [];
        foreach ($users_by_repo as $repo_id => $repo) {
            $students = $repo['students'] ?? [];
            $stats = $this->calculateFromStudents(array_map(function($s){
                // Normalizar claves esperadas
                return [ 'commit_count' => isset($s->cantidad_commits) ? (int)$s->cantidad_commits : (int)($s['commit_count'] ?? 0) ];
            }, $students));

            $result[$repo_id] = [
                'repo_id' => $repo_id,
                'nombre_repo' => $repo['nombre_repo'] ?? ($repo['nombre'] ?? ''),
                'sede' => $repo['sede'] ?? '',
                'curso' => $repo['curso'] ?? '',
                'nro' => $repo['nro'] ?? '',
                'stats' => $stats,
            ];
        }
        return $result;
    }

    /**
     * Construye arrays para etiquetas y series de Chart.js a partir de un array de estudiantes.
     * Retorna ['labels' => [], 'commits' => [], 'lines' => []]
     */
    public function chartArraysFromStudents(array $students): array {
        $labels = [];
        $commits = [];
        $lines = [];
        $lines_added = [];
        $lines_deleted = [];
        $lines_modified = [];

        foreach ($students as $s) {
            if (is_object($s)) {
                $full = $s->full_name ?? ($s->nombre_usuario ?? '');
                $gh = $s->github_username ?? ($s->usuario_github ?? ($s->nombre_usuario ?? ''));
                $c = isset($s->commit_count) ? (int)$s->commit_count : (int)($s->cantidad_commits ?? 0);
                $l = isset($s->lines_added) ? (int)$s->lines_added : (int)($s->lineas_agregadas ?? 0);
            } else {
                $full = $s['full_name'] ?? ($s['nombre_usuario'] ?? '');
                $gh = $s['github_username'] ?? ($s['usuario_github'] ?? ($s['nombre_usuario'] ?? ''));
                $c = isset($s['commit_count']) ? (int)$s['commit_count'] : (int)($s['cantidad_commits'] ?? 0);
                $l = isset($s['lines_added']) ? (int)$s['lines_added'] : (int)($s['lineas_agregadas'] ?? 0);
            }
            // build label safely
            $label = trim((string)$full);
            $gh = trim((string)$gh);
            if ($gh !== '') {
                $label = ($label !== '' ? $label . ' (' . $gh . ')' : '(' . $gh . ')');
            }

            // coerce numeric values to integers
            $c = is_numeric($c) ? (int)$c : 0;
            $l = is_numeric($l) ? (int)$l : 0;

            $deleted = 0;
            $modified = 0;
            if (is_object($s)) {
                $deleted = isset($s->lines_deleted) ? (int)$s->lines_deleted : (int)($s->lineas_eliminadas ?? 0);
                $modified = isset($s->lines_modified) ? (int)$s->lines_modified : (int)($s->lineas_modificadas ?? 0);
            } else {
                $deleted = isset($s['lines_deleted']) ? (int)$s['lines_deleted'] : (int)($s['lineas_eliminadas'] ?? 0);
                $modified = isset($s['lines_modified']) ? (int)$s['lines_modified'] : (int)($s['lineas_modificadas'] ?? 0);
            }

            $deleted = is_numeric($deleted) ? (int)$deleted : 0;
            $modified = is_numeric($modified) ? (int)$modified : 0;

            $labels[] = $label;
            $commits[] = $c;
            $lines[] = $l;
            $lines_added[] = $l;
            $lines_deleted[] = $deleted;
            $lines_modified[] = $modified;
        }

        // Ensure all arrays have the same length as labels and contain integers
        $N = count($labels);
        $pad = function(array $arr) use ($N) {
            $res = array_map(function($v){ return is_numeric($v) ? (int)$v : 0; }, $arr);
            while (count($res) < $N) $res[] = 0;
            if (count($res) > $N) $res = array_slice($res, 0, $N);
            return $res;
        };

        return [
            'labels' => $labels,
            'commits' => $pad($commits),
            'lines' => $pad($lines),
            'lines_added' => $pad($lines_added),
            'lines_deleted' => $pad($lines_deleted),
            'lines_modified' => $pad($lines_modified),
        ];
    }

    /**
     * Construye arrays de chart a partir de la estructura users_by_repo (todos los repos y sus estudiantes)
     */
    public function chartArraysFromRepos(array $users_by_repo): array {
        $all = [];
        foreach ($users_by_repo as $repo) {
            $students = $repo['students'] ?? [];
            foreach ($students as $s) {
                $all[] = $s;
            }
        }
        return $this->chartArraysFromStudents($all);
    }

}
