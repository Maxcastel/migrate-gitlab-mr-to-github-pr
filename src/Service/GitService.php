<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ImportException;
use Symfony\Component\Process\Exception\InvalidArgumentException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException;

class GitService
{
    public function __construct(
        private GitProcessRunner $processRunner = new GitProcessRunner(),
        private GitCredentials $credentials = new GitCredentials(),
    ) {}

    /**
     * @param list<string> $args
     *
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    private function git(array $args, ?string $cwd, bool $skipSslVerification): string
    {
        $env = $skipSslVerification ? ['GIT_SSL_NO_VERIFY' => 'true'] : [];

        $process = $this->processRunner->create(['git', ...$args], $cwd, $env);
        $result = $this->processRunner->run($process);

        if (!$result['successful']) {
            $message = trim($result['errorOutput'] ?: $result['output']);
            throw new ImportException(\sprintf('git %s failed: %s', $this->credentials->redact(implode(' ', $args)), $this->credentials->redact($message)));
        }

        return $result['output'];
    }

    /**
     * Mirror-clone $projectUrl into $repoDir.
     *
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function cloneMirror(string $projectUrl, string $repoDir, string $cwd, bool $skipSslVerification): void
    {
        $this->git(['clone', '--mirror', $projectUrl, $repoDir], $cwd, $skipSslVerification);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function addRemote(string $repoDir, string $remote, string $repoUrl, string $token): void
    {
        $this->git(['remote', 'add', $remote, $this->credentials->addTokenToUrl($repoUrl, $token)], $repoDir, false);
    }

    /**
     * @return list<string> every local branch
     *
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function getAllBranches(string $repoDir): array
    {
        $output = $this->git(['for-each-ref', '--format=%(refname:strip=2)', 'refs/heads/'], $repoDir, false);

        $branches = array_filter(array_map('trim', explode("\n", $output)));

        return array_values(array_unique($branches));
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function pushAllBranches(string $repoDir, string $remote, bool $skipSslVerification): void
    {
        $this->git(['push', '-u', $remote, '--all'], $repoDir, $skipSslVerification);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function pushBranch(string $repoDir, string $remote, string $branch, bool $skipSslVerification): void
    {
        $ref = 'refs/heads/'.$branch;
        $this->git(['push', '-u', $remote, $ref.':'.$ref], $repoDir, $skipSslVerification);
    }
}
