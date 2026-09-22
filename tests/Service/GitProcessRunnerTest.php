<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\InvalidArgumentException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

final class GitProcessRunnerTest extends TestCase
{
    /**
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function testCreateDisablesGitPromptsMergesCallerEnvAndBoundsTheProcessInTime(): void
    {
        $process = (new GitProcessRunner())->create(
            ['git', 'clone', '--mirror', 'https://example.com/r.git'],
            '/some/cwd',
            ['GIT_SSL_NO_VERIFY' => 'true'],
        );

        $env = $process->getEnv();
        self::assertSame('0', $env['GIT_TERMINAL_PROMPT'] ?? null, 'GIT_TERMINAL_PROMPT=0 must be forced so git never blocks on a credential prompt');
        self::assertSame('true', $env['GIT_SSL_NO_VERIFY'] ?? null, 'the caller env must be merged in');
        self::assertSame('/some/cwd', $process->getWorkingDirectory(), 'cwd must be forwarded to the Process');
        self::assertEqualsWithDelta(600.0, $process->getTimeout(), \PHP_FLOAT_EPSILON, 'a hard timeout must be set so a wedged git can never hang forever');

        $commandLine = $process->getCommandLine();
        foreach (['git', 'clone', '--mirror', 'https://example.com/r.git'] as $arg) {
            self::assertStringContainsString($arg, $commandLine, \sprintf("command argument '%s' must reach the Process", $arg));
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function testCreateLeavesTheProcessUnstarted(): void
    {
        $process = (new GitProcessRunner())->create(['git', '--version'], null, []);

        self::assertFalse($process->isStarted(), 'create() must build the Process without spawning anything');
    }

    /**
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testRunStartsTheProcessAndMapsASuccessfulOutcome(): void
    {
        $result = (new GitProcessRunner())->run($this->processDouble(true, 'git version 2.42.0', ''));

        self::assertSame(['successful' => true, 'output' => 'git version 2.42.0', 'errorOutput' => ''], $result, 'a successful process must map to successful=true plus both captured streams');
    }

    /**
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testRunStartsTheProcessAndMapsAFailedOutcome(): void
    {
        $result = (new GitProcessRunner())->run($this->processDouble(false, 'partial', 'fatal: repository not found'));

        self::assertSame(['successful' => false, 'output' => 'partial', 'errorOutput' => 'fatal: repository not found'], $result, 'a failed process must map to successful=false plus both captured streams');
    }

    private function processDouble(bool $successful, string $output, string $errorOutput): Process
    {
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('run')->willReturn($successful ? 0 : 1);
        $process->method('isSuccessful')->willReturn($successful);
        $process->method('getOutput')->willReturn($output);
        $process->method('getErrorOutput')->willReturn($errorOutput);

        return $process;
    }
}
