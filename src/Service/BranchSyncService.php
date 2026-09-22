<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ImportException;
use Exception;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\InvalidArgumentException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException;

class BranchSyncService
{
    private const REMOTE = 'github';

    private string $tempDir;

    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
        private GitService $git = new GitService(),
    ) {
        $this->tempDir = sys_get_temp_dir().'/gitlab-github-sync-'.uniqid();
    }

    /**
     * Clone the GitLab repo and push every regular branch to GitHub.
     *
     * For merged MRs whose source branch was deleted on GitLab after merging,
     * the commit content still travels through main's history (parent[1] of
     * each merge commit), so GitHubService can recreate the source branch from
     * the MR's commitSha via `git_data->references->create`.
     *
     * @return array{synced: list<string>, failed: array<string, string>, total: int}
     *
     * @throws ImportException
     * @throws IOException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function syncBranches(
        string $gitLabRepoUrl,
        string $gitHubRepoUrl,
        string $gitHubToken,
        bool $skipSslCertificateVerification = false,
    ): array {
        try {
            $this->prepareTempDirectory();
            $this->git->cloneMirror($gitLabRepoUrl, $this->repoDir(), $this->tempDir, $skipSslCertificateVerification);
            $this->git->addRemote($this->repoDir(), self::REMOTE, $gitHubRepoUrl, $gitHubToken);

            $branches = $this->git->getAllBranches($this->repoDir());

            $syncedBranches = [];
            $failedBranches = [];

            try {
                $this->git->pushAllBranches($this->repoDir(), self::REMOTE, $skipSslCertificateVerification);
                $syncedBranches = $branches;
            } catch (Exception $e) {
                foreach ($branches as $branch) {
                    try {
                        $this->git->pushBranch($this->repoDir(), self::REMOTE, $branch, $skipSslCertificateVerification);
                        $syncedBranches[] = $branch;
                    } catch (Exception $branchException) {
                        $failedBranches[$branch] = $branchException->getMessage();
                    }
                }
            }

            return [
                'synced' => $syncedBranches,
                'failed' => $failedBranches,
                'total' => \count($branches),
            ];
        } finally {
            $this->cleanupTempDirectory();
        }
    }

    private function repoDir(): string
    {
        return $this->tempDir.'/repo.git';
    }

    /**
     * @throws IOException
     */
    private function prepareTempDirectory(): void
    {
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }

        $this->filesystem->mkdir($this->tempDir);
    }

    private function cleanupTempDirectory(): void
    {
        if (!$this->filesystem->exists($this->tempDir)) {
            return;
        }

        try {
            $this->filesystem->remove($this->tempDir);
        } catch (Exception $exception) {
        }
    }
}
