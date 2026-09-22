<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ImportException;
use App\Service\BranchSyncService;
use App\Service\GitProcessRunner;
use App\Service\GitService;
use App\Tests\Helper\ServiceMockHelper;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Process;

final class BranchSyncServiceTest extends TestCase
{
    /** @var list<array{command: list<string>, cwd: ?string, env: array<string, string>}> */
    private array $processCalls = [];

    /**
     * @param Closure(list<string>): array{successful: bool, output: string, errorOutput: string} $runGit
     */
    private function makeService(Closure $runGit, ?Filesystem $filesystem = null): BranchSyncService
    {
        $runner = self::createStub(GitProcessRunner::class);
        $runner->method('create')->willReturnCallback($this->recordCall(...));
        $runner->method('run')->willReturnCallback(
            fn (): array => $runGit($this->lastCall()['command'])
        );

        return new BranchSyncService($filesystem ?? $this->stubFilesystem(), new GitService($runner));
    }

    /**
     * @return array{successful: bool, output: string, errorOutput: string}
     */
    private function okResult(string $output = ''): array
    {
        return ['successful' => true, 'output' => $output, 'errorOutput' => ''];
    }

    /**
     * @return array{successful: bool, output: string, errorOutput: string}
     */
    private function koResult(string $errorOutput): array
    {
        return ['successful' => false, 'output' => '', 'errorOutput' => $errorOutput];
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
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function tempDirOf(BranchSyncService $svc): string
    {
        $tempDir = ServiceMockHelper::getPrivateProperty($svc, 'tempDir');
        self::assertIsString($tempDir);

        return $tempDir;
    }

    /**
     * @return list<string>
     */
    private function gitArgs(int $index): array
    {
        return \array_slice($this->processCalls[$index]['command'], 1);
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws IOException
     * @throws LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function testSyncBranchesClonesAddsRemoteListsThenBulkPushesOnHappyPath(): void
    {
        $svc = $this->makeService(function (array $command): array {
            if (\in_array('for-each-ref', $command, true)) {
                return $this->okResult("main\nfeat");
            }

            return $this->okResult();
        }, $this->stubFilesystem());

        $result = $svc->syncBranches('https://gitlab.com/g/p.git', 'https://github.com/u/r.git', 'ghtok');

        self::assertSame(['synced' => ['main', 'feat'], 'failed' => [], 'total' => 2], $result);

        $tempDir = $this->tempDirOf($svc);
        $repoDir = $tempDir.'/repo.git';

        self::assertSame(['clone', '--mirror', 'https://gitlab.com/g/p.git', $repoDir], $this->gitArgs(0));
        self::assertSame($tempDir, $this->processCalls[0]['cwd']);
        self::assertSame([], $this->processCalls[0]['env']);

        self::assertSame(['remote', 'add', 'github', 'https://ghtok@github.com/u/r.git'], $this->gitArgs(1));
        self::assertSame($repoDir, $this->processCalls[1]['cwd']);

        self::assertSame(['for-each-ref', '--format=%(refname:strip=2)', 'refs/heads/'], $this->gitArgs(2));
        self::assertSame($repoDir, $this->processCalls[2]['cwd']);

        self::assertSame(['push', '-u', 'github', '--all'], $this->gitArgs(3));
        self::assertSame($repoDir, $this->processCalls[3]['cwd']);

        self::assertCount(4, $this->processCalls);
    }

    /**
     * @throws ImportException
     * @throws IOException
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException
     * @throws LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function testSyncBranchesPassesSslSkipEnvOnlyToCloneAndPushWhenRequested(): void
    {
        $svc = $this->makeService(function (array $command): array {
            if (\in_array('for-each-ref', $command, true)) {
                return $this->okResult('main');
            }

            return $this->okResult();
        }, $this->stubFilesystem());

        $svc->syncBranches('https://gitlab.com/g/p.git', 'https://github.com/u/r.git', 'ghtok', true);

        self::assertSame(['GIT_SSL_NO_VERIFY' => 'true'], $this->processCalls[0]['env'], 'clone must carry SSL-skip env');
        self::assertSame([], $this->processCalls[1]['env'], 'remote add must not carry SSL-skip env');
        self::assertSame([], $this->processCalls[2]['env'], 'for-each-ref must not carry SSL-skip env');
        self::assertSame(['GIT_SSL_NO_VERIFY' => 'true'], $this->processCalls[3]['env'], 'push must carry SSL-skip env');
    }

    /**
     * @throws ImportException
     * @throws IOException
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException
     * @throws LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function testSyncBranchesFallsBackToPerBranchPushWhenBulkPushFails(): void
    {
        $svc = $this->makeService(function (array $command): array {
            if (\in_array('for-each-ref', $command, true)) {
                return $this->okResult("main\nfeat");
            }

            if (\in_array('--all', $command, true)) {
                return $this->koResult('bulk push rejected');
            }

            return $this->okResult();
        }, $this->stubFilesystem());

        $result = $svc->syncBranches('https://gitlab.com/g/p.git', 'https://github.com/u/r.git', 'ghtok');

        self::assertSame(['main', 'feat'], $result['synced']);
        self::assertSame([], $result['failed']);
        self::assertSame(2, $result['total']);

        self::assertSame(['push', '-u', 'github', 'refs/heads/main:refs/heads/main'], $this->gitArgs(4));
        self::assertSame(['push', '-u', 'github', 'refs/heads/feat:refs/heads/feat'], $this->gitArgs(5));
    }

    /**
     * @throws ImportException
     * @throws IOException
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException
     * @throws LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function testSyncBranchesCollectsPerBranchFailureAndKeepsGoing(): void
    {
        $svc = $this->makeService(function (array $command): array {
            if (\in_array('for-each-ref', $command, true)) {
                return $this->okResult("main\nfeat");
            }

            if (\in_array('--all', $command, true)) {
                return $this->koResult('bulk failed');
            }

            if (\in_array('refs/heads/feat:refs/heads/feat', $command, true)) {
                return $this->koResult('protected branch');
            }

            return $this->okResult();
        }, $this->stubFilesystem());

        $result = $svc->syncBranches('https://gitlab.com/g/p.git', 'https://github.com/u/r.git', 'ghtok');

        self::assertSame(['main'], $result['synced'], 'only the branch that pushed cleanly is synced');
        self::assertArrayHasKey('feat', $result['failed']);
        self::assertStringContainsString('protected branch', (string) $result['failed']['feat']);
        self::assertSame(2, $result['total']);
    }

    /**
     * @throws ImportException
     * @throws IOException
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException
     * @throws LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function testSyncBranchesTrimsFiltersEmptyAndDeduplicatesBranchNames(): void
    {
        $svc = $this->makeService(function (array $command): array {
            if (\in_array('for-each-ref', $command, true)) {
                return $this->okResult("main\n  feat  \n\nmain\n");
            }

            if (\in_array('--all', $command, true)) {
                return $this->koResult('force per-branch to reveal the parsed list');
            }

            return $this->okResult();
        }, $this->stubFilesystem());

        $result = $svc->syncBranches('https://gitlab.com/g/p.git', 'https://github.com/u/r.git', 'ghtok');

        self::assertSame(['main', 'feat'], $result['synced']);
        self::assertSame(2, $result['total']);

        $pushRefs = [];
        foreach ($this->processCalls as $call) {
            $args = \array_slice($call['command'], 1);
            if (($args[0] ?? null) === 'push' && ($args[3] ?? null) !== '--all') {
                $pushRefs[] = $args[3];
            }
        }

        self::assertSame(['refs/heads/main:refs/heads/main', 'refs/heads/feat:refs/heads/feat'], $pushRefs);
    }

    /**
     * @throws IOException
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException
     * @throws LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function testSyncBranchesRethrowsRedactedErrorAndCleansUpWhenCloneFails(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);
        $filesystem->expects(self::exactly(2))->method('remove');

        $svc = $this->makeService(
            fn (array $command): array => $this->koResult("fatal: could not read from https://oauth2:glpat-SECRET@gitlab.com/g/p.git\n  "),
            $filesystem
        );

        try {
            $svc->syncBranches('https://oauth2:glpat-SECRET@gitlab.com/g/p.git', 'https://github.com/u/r.git', 'ghtok');
            self::fail('Expected ImportException was not thrown');
        } catch (ImportException $importException) {
            self::assertStringContainsString('git clone --mirror', $importException->getMessage());
            self::assertStringContainsString('failed:', $importException->getMessage());
            self::assertStringContainsString('***@gitlab.com', $importException->getMessage(), 'command URL must be redacted');
            self::assertStringNotContainsString('glpat-SECRET', $importException->getMessage(), 'the token must never appear');
            self::assertStringEndsWith('gitlab.com/g/p.git', $importException->getMessage(), "git's output must be trimmed");
        }
    }

    /**
     * @throws IOException
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException
     * @throws LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function testGitFallsBackToStdoutWhenErrorOutputIsEmptyOnFailure(): void
    {
        $filesystem = self::createStub(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $svc = $this->makeService(
            static fn (array $command): array => ['successful' => false, 'output' => 'exit status 128', 'errorOutput' => ''],
            $filesystem
        );

        try {
            $svc->syncBranches('https://gitlab.com/g/p.git', 'https://github.com/u/r.git', 'ghtok');
            self::fail('Expected ImportException was not thrown');
        } catch (ImportException $importException) {
            self::assertStringContainsString('exit status 128', $importException->getMessage(), 'when errorOutput is empty, the message must fall back to stdout');
        }
    }

    /**
     * @throws ImportException
     * @throws IOException
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException
     * @throws LogicException
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     */
    public function testGitReturnsProcessOutputOnSuccess(): void
    {
        $svc = $this->makeService(function (array $command): array {
            if (\in_array('for-each-ref', $command, true)) {
                return $this->okResult('only-branch');
            }

            return $this->okResult();
        }, $this->stubFilesystem());

        $result = $svc->syncBranches('https://gitlab.com/g/p.git', 'https://github.com/u/r.git', 'ghtok');

        self::assertSame(['only-branch'], $result['synced']);
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function testPrepareTempDirectoryRemovesExistingDirectoryThenCreatesIt(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $svc = $this->makeService(fn (): array => $this->okResult(), $filesystem);
        $tempDir = $this->tempDirOf($svc);

        $filesystem->method('exists')->with($tempDir)->willReturn(true);
        $filesystem->expects(self::once())->method('remove')->with($tempDir);
        $filesystem->expects(self::once())->method('mkdir')->with($tempDir);

        $this->invokePrivate($svc, 'prepareTempDirectory');
    }

    /**
     * @throws ReflectionException
     */
    public function testPrepareTempDirectorySkipsRemovalWhenDirectoryAbsent(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $svc = $this->makeService(fn (): array => $this->okResult(), $filesystem);

        $filesystem->method('exists')->willReturn(false);
        $filesystem->expects(self::never())->method('remove');
        $filesystem->expects(self::once())->method('mkdir');

        $this->invokePrivate($svc, 'prepareTempDirectory');
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function testCleanupTempDirectoryRemovesDirectoryWhenPresent(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $svc = $this->makeService(fn (): array => $this->okResult(), $filesystem);
        $tempDir = $this->tempDirOf($svc);

        $filesystem->method('exists')->with($tempDir)->willReturn(true);
        $filesystem->expects(self::once())->method('remove')->with($tempDir);

        $this->invokePrivate($svc, 'cleanupTempDirectory');
    }

    /**
     * @throws ReflectionException
     */
    public function testCleanupTempDirectorySwallowsRemovalFailure(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $svc = $this->makeService(fn (): array => $this->okResult(), $filesystem);

        $filesystem->method('exists')->willReturn(true);
        $filesystem->expects(self::once())->method('remove')->willThrowException(new RuntimeException('locked'));

        $this->invokePrivate($svc, 'cleanupTempDirectory');
    }

    /**
     * @throws ReflectionException
     */
    public function testCleanupTempDirectoryDoesNothingWhenAbsent(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $svc = $this->makeService(fn (): array => $this->okResult(), $filesystem);

        $filesystem->method('exists')->willReturn(false);
        $filesystem->expects(self::never())->method('remove');

        $this->invokePrivate($svc, 'cleanupTempDirectory');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testTempDirIsUnderSystemTempWithUniqueSuffix(): void
    {
        $svc = new BranchSyncService();
        $tempDir = $this->tempDirOf($svc);

        $systemTempDir = sys_get_temp_dir();
        self::assertNotSame('', $systemTempDir);
        self::assertStringStartsWith($systemTempDir, $tempDir);
        self::assertMatchesRegularExpression('#/gitlab-github-sync-[0-9a-f.]+$#', $tempDir);

        self::assertNotSame($tempDir, $this->tempDirOf(new BranchSyncService()), 'each instance gets a unique temp dir');
    }

    private function stubFilesystem(): Filesystem
    {
        $filesystem = self::createStub(Filesystem::class);
        $filesystem->method('exists')->willReturn(false);

        return $filesystem;
    }

    /**
     * @throws ReflectionException
     */
    private function invokePrivate(BranchSyncService $svc, string $method): void
    {
        (new ReflectionMethod(BranchSyncService::class, $method))->invoke($svc);
    }
}
