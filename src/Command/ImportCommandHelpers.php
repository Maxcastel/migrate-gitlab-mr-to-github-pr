<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\ImportException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;

trait ImportCommandHelpers
{
    /**
     * @throws ImportException
     * @throws LogicException
     * @throws RuntimeException
     * @throws ParameterNotFoundException
     */
    private function retrieveParameter(
        InputInterface $input,
        OutputInterface $output,
        string $name,
    ): string {
        $option = $input->getOption($name);
        if (\is_scalar($option) && $option) {
            return (string) $option;
        }

        if ($this->params->has($name)) {
            $parameter = $this->params->get($name);
            if (\is_scalar($parameter) && $parameter) {
                return (string) $parameter;
            }
        }

        $answer = $this->getHelper('question')->ask($input, $output, new Question($name.'? '));
        if (!\is_scalar($answer)) {
            throw new ImportException(\sprintf('No value provided for "%s"', $name));
        }

        return (string) $answer;
    }

    protected function createProgressBar(OutputInterface $output, int $max): ProgressBar
    {
        ProgressBar::setFormatDefinition('custom', '[%bar%] %current%/%max% %message%');

        $progressBar = new ProgressBar($output, $max);
        $progressBar->setFormat('custom');
        $progressBar->setBarCharacter('<fg=green>█</>');
        $progressBar->setProgressCharacter('<fg=green>█</>');
        $progressBar->setEmptyBarCharacter('<fg=gray>▒</>');
        $progressBar->setMessage('');

        return $progressBar;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue>                  $items
     * @param callable(TKey, TValue): list<string> $mapper
     *
     * @return list<list<string>>
     */
    private function mapToRows(array $items, callable $mapper): array
    {
        $rows = [];
        foreach ($items as $key => $value) {
            $rows[] = $mapper($key, $value);
        }

        return $rows;
    }
}
