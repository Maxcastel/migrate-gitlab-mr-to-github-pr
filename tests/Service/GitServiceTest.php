<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ImportException;
use App\Service\GitProcessRunner;
use App\Service\GitService;
use Closure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\InvalidArgumentException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

final class GitServiceTest extends TestCase
{
    /** @var list<array{command: list<string>, cwd: ?string, env: array<string, string>}> */
    private array $processCalls = [];

    /**
     * @param Closure(list<string>): array{successful: bool, output: string, errorOutput: string} $runGit
     */
    private function makeService(Closure $runGit): GitService
    {
        $runner = self::createStub(GitProcessRunner::class);
        $runner->method('create')->willReturnCallback($this->recordCall(...));
        $runner->method('run')->willReturnCallback(
            fn (): array => $runGit($this->lastCall()['command'])
        );

        return new GitService($runner);
    }

    private function okService(string $output = ''): GitService
    {
        return $this->makeService(static fn (): array => ['successful' => true, 'output' => $output, 'errorOutput' => '']);
    }

    private function failingService(string $errorOutput, string $output = ''): GitService
    {
        return $this->makeService(static fn (): array => ['successful' => false, 'output' => $output, 'errorOutput' => $errorOutput]);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    private function recordCall(array $command, ?string $cwd, array $env): Process
    {
        $this->processCalls[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env];

        return self::createStub(Process::class);
    }

    /**
     * @return array{command: list<string>, cwd: ?string, env: array<string, string>}
     */
    private function lastCall(): array
    {
        $calls = $this->processCalls;
        $lastCall = end($calls);
        self::assertNotFalse($lastCall, 'no git command has been recorded yet');

        return $lastCall;
    }

    /**
     * @return list<string>
     */
    private function lastArgs(): array
    {
        return \array_slice($this->lastCall()['command'], 1);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testCloneMirrorRunsAMirrorCloneFromTheGivenWorkingDirectory(): void
    {
        $this->okService()->cloneMirror('https://gitlab.com/g/p.git', '/tmp/ws/repo.git', '/tmp/ws', false);

        self::assertSame('git', $this->lastCall()['command'][0], 'every command must be a git command');
        self::assertSame(['clone', '--mirror', 'https://gitlab.com/g/p.git', '/tmp/ws/repo.git'], $this->lastArgs());
        self::assertSame('/tmp/ws', $this->lastCall()['cwd']);
        self::assertSame([], $this->lastCall()['env'], 'no SSL-skip env unless asked');
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testCloneMirrorCarriesTheSslSkipEnvWhenRequested(): void
    {
        $this->okService()->cloneMirror('https://gitlab.com/g/p.git', '/tmp/ws/repo.git', '/tmp/ws', true);

        self::assertSame(['GIT_SSL_NO_VERIFY' => 'true'], $this->lastCall()['env']);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testAddRemoteRunsInTheRepositoryAndNeverSkipsSslVerification(): void
    {
        $this->okService()->addRemote('/tmp/ws/repo.git', 'github', 'https://github.com/u/r.git', 'ghtok');

        self::assertSame(['remote', 'add', 'github', 'https://ghtok@github.com/u/r.git'], $this->lastArgs());
        self::assertSame('/tmp/ws/repo.git', $this->lastCall()['cwd']);
        self::assertSame([], $this->lastCall()['env'], 'adding a remote is a local operation: it must never carry the SSL-skip env');
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testGetAllBranchesReadsRefsHeadsWithoutDecorations(): void
    {
        $branches = $this->okService('main')->getAllBranches('/tmp/ws/repo.git');

        self::assertSame(['main'], $branches);
        self::assertSame(['for-each-ref', '--format=%(refname:strip=2)', 'refs/heads/'], $this->lastArgs());
        self::assertSame('/tmp/ws/repo.git', $this->lastCall()['cwd']);
        self::assertSame([], $this->lastCall()['env']);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testGetAllBranchesTrimsAndDropsEmptyNames(): void
    {
        $branches = $this->okService("main\n  feat  \n\n")->getAllBranches('/tmp/ws/repo.git');

        self::assertSame(['main', 'feat'], $branches);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testGetAllBranchesDeduplicatesAndReindexesTheKeys(): void
    {
        $branches = $this->okService("main\nmain\nfeat")->getAllBranches('/tmp/ws/repo.git');

        self::assertSame(['main', 'feat'], $branches, 'keys must be reindexed to [0, 1]');
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testPushAllBranchesPushesEveryBranchInOneGo(): void
    {
        $this->okService()->pushAllBranches('/tmp/ws/repo.git', 'github', false);

        self::assertSame(['push', '-u', 'github', '--all'], $this->lastArgs());
        self::assertSame('/tmp/ws/repo.git', $this->lastCall()['cwd']);
        self::assertSame([], $this->lastCall()['env']);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testPushAllBranchesCarriesTheSslSkipEnvWhenRequested(): void
    {
        $this->okService()->pushAllBranches('/tmp/ws/repo.git', 'github', true);

        self::assertSame(['GIT_SSL_NO_VERIFY' => 'true'], $this->lastCall()['env']);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testPushBranchPushesTheFullyQualifiedRefspec(): void
    {
        $this->okService()->pushBranch('/tmp/ws/repo.git', 'github', 'feat', false);

        self::assertSame(['push', '-u', 'github', 'refs/heads/feat:refs/heads/feat'], $this->lastArgs());
        self::assertSame('/tmp/ws/repo.git', $this->lastCall()['cwd']);
        self::assertSame([], $this->lastCall()['env']);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testPushBranchCarriesTheSslSkipEnvWhenRequested(): void
    {
        $this->okService()->pushBranch('/tmp/ws/repo.git', 'github', 'feat', true);

        self::assertSame(['GIT_SSL_NO_VERIFY' => 'true'], $this->lastCall()['env']);
    }

    /**
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testAFailingCommandThrowsWithTheCommandAndOutputRedactedAndTrimmed(): void
    {
        $service = $this->failingService("fatal: could not read from https://oauth2:glpat-SECRET@gitlab.com/g/p.git\n  ");

        try {
            $service->cloneMirror('https://oauth2:glpat-SECRET@gitlab.com/g/p.git', '/tmp/ws/repo.git', '/tmp/ws', false);
            self::fail('Expected ImportException was not thrown');
        } catch (ImportException $importException) {
            self::assertStringContainsString('git clone --mirror', $importException->getMessage());
            self::assertStringContainsString('failed:', $importException->getMessage());
            self::assertStringContainsString('***@gitlab.com', $importException->getMessage(), 'the command URL must be redacted');
            self::assertStringNotContainsString('glpat-SECRET', $importException->getMessage(), 'the token must never appear');
            self::assertStringEndsWith('gitlab.com/g/p.git', $importException->getMessage(), "git's output must be trimmed");
        }
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testAFailingCommandFallsBackToStdoutWhenStderrIsEmpty(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('exit status 128');

        $this->failingService('', 'exit status 128')->pushAllBranches('/tmp/ws/repo.git', 'github', false);
    }
}
