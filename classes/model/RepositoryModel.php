<?php
namespace mod_pluginpatroller\model;

defined('MOODLE_INTERNAL') || die();

class RepositoryModel {

    /**
     * Obtiene todos los repositorios de un curso.
     *
     * @param int $courseId
     * @return array [repoId => repoName]
     */
    public static function getAllByCourseId(int $courseId): array {
        global $DB;

        $repos = $DB->get_records('repositorios_data_patroller', ['id_materia' => $courseId]);
        $result = [];

        foreach ($repos as $repo) {
            $result[$repo->id] = $repo->nombre_repo;
        }

        return $result;
    }

    /**
     * Obtiene un repositorio por su ID.
     *
     * @param int $repoId
     * @return object|null
     */
    public static function getById(int $repoId): ?object {
        global $DB;

        return $DB->get_record('repositorios_data_patroller', ['id' => $repoId]);
    }

    /**
     * Obtiene todos los repositorios de una sede y curso específicos.
     *
     * @param int $courseId
     * @param string $sede
     * @param string $curso
     * @return array
     */
    public static function getBySedeAndCurso(int $courseId, string $sede, string $curso): array {
        global $DB;

        return $DB->get_records('repositorios_data_patroller', [
            'id_materia' => $courseId,
            'sede' => $sede,
            'curso' => $curso
        ]);
    }

    /**
     * Obtiene el número de grupo más alto para una sede y curso.
     *
     * @param array $repos Lista de repositorios (puede venir de getBySedeAndCurso)
     * @return int
     */
    public static function getLastRepoNum(array $repos): int {
        $max = 0;

        foreach ($repos as $repo) {
            if ($repo->num_grupo > $max) {
                $max = $repo->num_grupo;
            }
        }

        return $max;
    }
}
