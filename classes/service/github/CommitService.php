<?php
namespace mod_pluginpatroller\service\github;

use mod_pluginpatroller\model\UserModel;
use mod_pluginpatroller\model\RepositoryModel;
use mod_pluginpatroller\model\ghpatapi\GitHubRegistrationStatus;
use mod_pluginpatroller\model\ghpatapi\GitHubPatrollerAPI;
use mod_pluginpatroller\service\github\GitHubApiService;

defined('MOODLE_INTERNAL') || die();

class CommitService {

    private GitHubPatrollerAPI $githubAPI;

    public function __construct() {
        $apiService = new GitHubApiService();
        $this->githubAPI = $apiService->getAPIOrFail();
    }

    public function updateCommits(int $courseId): void {
        $fechaSegura = date('Y-m-d\TH:i:s\Z', strtotime('-1 year'));
        $repoList = RepositoryModel::getAllByCourseId($courseId);

        foreach ($repoList as $repoId => $repoName) {
            $this->updateCommitsForRepo($repoId, $repoName, $fechaSegura);
        }
    }

    public function updateCommitsForRepo(int $repoId, string $repoName, string $fechaSegura): void {
        global $DB;
        $students = $DB->get_records('usuarios_data_patroller', ['id_repo' => $repoId]);
        
        if (empty($students)) {
            return;
        }
        
        foreach ($students as $student) {
            if (empty($student->usuario_github)) continue;

            $commits = $this->githubAPI->getCommitsByRepoAndUser($repoName, $student->usuario_github, $fechaSegura);

            $commit_count = count($commits);
            $lines_added = 0;
            $lines_deleted = 0;
            $lines_modified = 0;
            $last_commit_date = '';

            foreach ($commits as $commit) {
                $sha = $commit['sha'] ?? null;
                if ($sha) {
                    $stats = $this->githubAPI->getCommitStats($repoName, $sha);
                    $a = $stats['additions'] ?? 0;
                    $d = $stats['deletions'] ?? 0;
                    $t = $stats['total'] ?? 0;
                    $lines_added += $a;
                    $lines_deleted += $d;
                    $lines_modified += $t;
                    // per-commit stats removed from logs in cleanup
                }
                if (!$last_commit_date && isset($commit['commit']['author']['date'])) {
                    $last_commit_date = $commit['commit']['author']['date'];
                }
            }

            // Summary logging removed in cleanup

            $update = (object)[
                'id' => $student->id,
                'cantidad_commits' => $commit_count,
                'lineas_agregadas' => $lines_added,
                'lineas_eliminadas' => $lines_deleted,
                'lineas_modificadas' => $lines_modified,
                'fecha_ultimo_commit' => $last_commit_date,
            ];

            // Perform DB update
            $DB->update_record('usuarios_data_patroller', $update);
        }
    }

    // public function getContributionsSnapshot(int $courseId): array {
    //     // Implementación futura para estadísticas por usuario/repositorio
    //     // Esta función puede implementar la lógica de get_all_contributions_snapshot()
    //     // y retornar un array con estadísticas por usuario y repositorio.
    //     return [];
    // }
}