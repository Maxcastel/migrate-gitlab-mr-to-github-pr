<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Issue;
use App\Exception\ImportException;
use App\Service\AttachmentMigrator;
use App\Service\GitHubService;
use App\Service\GitLabService;
use Exception;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use UnhandledMatchError;

#[AsCommand(name: 'import')]
class ImportCommand extends Command
{
    use ImportCommandHelpers;

    public function __construct(
        private ParameterBagInterface $params,
        private GitLabService $gitLabService,
        private GitHubService $gitHubService,
        private AttachmentMigrator $attachmentMigrator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->setDescription('Import issues')
            ->setHelp('Imports issues from the GitLab repository into the GitHub repository')
            ->addOption(
                'gitLabToken',
                null,
                InputOption::VALUE_OPTIONAL,
                'The GitLab token'
            )
            ->addOption(
                'gitLabProjectId',
                null,
                InputOption::VALUE_OPTIONAL,
                'The GitLab source project Id'
            )
            ->addOption(
                'gitHubToken',
                null,
                InputOption::VALUE_OPTIONAL,
                'The GitHub token'
            )
            ->addOption(
                'gitHubUserName',
                null,
                InputOption::VALUE_OPTIONAL,
                'The GitHub user name'
            )
            ->addOption(
                'gitHubRepositoryName',
                null,
                InputOption::VALUE_OPTIONAL,
                'The GitHub destination repository name'
            )
            ->addOption(
                'gitHubImportDelayMs',
                null,
                InputOption::VALUE_OPTIONAL,
                'Milliseconds to wait between each issue import into GitHub to avoid triggering rate limits',
                '3000'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'The maximum number of issues to import. Already existing issues are not counted'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Perform a dry run to see what issues would be imported but without making any actual change'
            )
            ->addOption(
                'skipSslCertificateVerification',
                null,
                InputOption::VALUE_NONE,
                'Skip SSL certificate verification'
            )
        ;
    }

    private function microsecondsFromMilliseconds(int $milliseconds): int
    {
        return $milliseconds * 1000;
    }

    private function sleepBetweenImports(int $milliseconds): void
    {
        usleep($this->microsecondsFromMilliseconds($milliseconds));
    }

    /**
     * @throws ImportException
     * @throws RuntimeException
     * @throws ParameterNotFoundException
     * @throws UnhandledMatchError
     * @throws LogicException
     * @throws \Http\Client\Exception
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $gitLabToken = $this->retrieveParameter($input, $output, 'gitLabToken');
        $gitLabProjectId = (int) $this->retrieveParameter($input, $output, 'gitLabProjectId');
        $gitHubToken = $this->retrieveParameter($input, $output, 'gitHubToken');
        $gitHubUserName = $this->retrieveParameter($input, $output, 'gitHubUserName');
        $gitHubRepositoryName = $this->retrieveParameter($input, $output, 'gitHubRepositoryName');
        $gitHubImportDelayMs = (int) $this->retrieveParameter($input, $output, 'gitHubImportDelayMs');
        $limit = (int) $input->getOption('limit');
        $isDry = $input->getOption('dry-run');
        $skipSslCertificateVerification = $input->getOption('skipSslCertificateVerification');

        try {
            $output->writeln('Retrieving issues from GitLab project #'.$gitLabProjectId);

            $this->gitLabService->init($gitLabToken, $skipSslCertificateVerification);
            $issues = $this->gitLabService->getIssues($gitLabProjectId);

            if ([] === $issues) {
                $output->writeln('No issues found on GitLab project #'.$gitLabProjectId);

                return Command::SUCCESS;
            }

            $output->writeln(\count($issues).' issues found, importing'.(0 !== $limit ? \sprintf(' (%s max)', $limit) : null));

            $this->gitHubService->init($gitHubToken, $gitHubUserName, $gitHubRepositoryName, $skipSslCertificateVerification);

            $totalIssues = \count($issues);
            $importOk = 0;
            $erroredIssues = [];
            $alreadyImportedIssues = [];

            $progressBar = $this->createProgressBar($output, $totalIssues);
            $progressBar->start();

            foreach ($issues as $issue) {
                $isShouldWait = false;

                $outputLine = [];
                $outputLine[] = \sprintf('Issue #%s "%s"', $issue->gitLabId, $issue->title);

                try {
                    if ($this->gitHubService->isImported($issue)) {
                        $outputLine[] = '⏩ Already imported, skipping';
                        $alreadyImportedIssues[] = $issue;
                    } else {
                        if (!$isDry) {
                            $issue->description = $this->attachmentMigrator->migrateDescription($issue->description, $gitLabProjectId, $issue->gitLabUrl);
                            $this->gitHubService->importIssue($issue);
                            $isShouldWait = true;
                            $outputLine[] = '✅ Imported';
                        } else {
                            $outputLine[] = '✅ Would be imported if no dry run';
                        }

                        ++$importOk;
                    }
                } catch (ImportException $e) {
                    $outputLine[] = \sprintf('🚫 Error (%s)', $e->getMessage());
                    $erroredIssues[] = $issue;
                }

                $progressBar->setMessage(implode(' / ', $outputLine));
                $progressBar->advance();

                if ($isShouldWait) {
                    $this->sleepBetweenImports($gitHubImportDelayMs);
                }

                if ($limit && $importOk >= $limit) {
                    break;
                }
            }

            $progressBar->finish();

            if ($limit && $importOk >= $limit) {
                $output->writeln(\sprintf('Limit of %s imported issues reached', $limit));
            }

            $output->writeln('');
            $output->writeln('');

            if ([] !== $erroredIssues) {
                $table = new Table($output);
                $table
                    ->setHeaderTitle('Failed import issues')
                    ->setHeaders(['GitLab issue id', 'title'])
                    ->setRows(
                        $this->mapToRows(
                            $erroredIssues,
                            static fn (int $key, Issue $issue): array => [
                                '#'.$issue->gitLabId,
                                $issue->title,
                            ],
                        )
                    );
                $table->render();
            }

            $this->attachmentMigrator->displayWarnings($output);

            $output->writeln('');

            if ([] !== $alreadyImportedIssues) {
                $table = new Table($output);
                $table
                    ->setHeaderTitle('Already imported issues')
                    ->setHeaders(['GitLab issue id', 'title'])
                    ->setRows(
                        $this->mapToRows(
                            $alreadyImportedIssues,
                            static fn (int $key, Issue $issue): array => [
                                '#'.$issue->gitLabId,
                                $issue->title,
                            ],
                        )
                    );
                $table->render();
            }

            $output->writeln('');

            $table = new Table($output);
            $table
                ->setHeaderTitle($isDry ? 'Dry run result' : 'Importation result')
                ->setRows([
                    ['Total issues', $totalIssues],
                    ['Imported issues', $importOk],
                    ['Non imported issues', $totalIssues - $importOk],
                    ['Already imported issues', \count($alreadyImportedIssues)],
                    ['Failed imports', \count($erroredIssues)],
                ]);
            $table->render();

            return Command::SUCCESS;
        } catch (Exception $exception) {
            $output->writeln('Error: '.$exception->getMessage());

            return Command::FAILURE;
        }
    }
}
