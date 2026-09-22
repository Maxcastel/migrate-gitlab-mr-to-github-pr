<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Exception\InvalidArgumentException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

class GitProcessRunner
{
    private const TIMEOUT_SECONDS = 600;

    /**
     * @return array{successful: bool, output: string, errorOutput: string}
     *
     * @throws LogicException
     * @throws RuntimeException
     */
    public function run(Process $process): array
    {
        $process->run();

        return [
            'successful' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput(),
        ];
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @throws LogicException
     * @throws InvalidArgumentException
     */
    public function create(array $command, ?string $cwd, array $env): Process
    {
        $process = new Process($command, $cwd, ['GIT_TERMINAL_PROMPT' => '0'] + $env);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        return $process;
    }
}
