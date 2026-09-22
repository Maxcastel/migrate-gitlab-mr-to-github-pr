<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\GitHub\GitHubApiClient;
use App\Client\GitHub\Response\BranchProtection;
use App\Entity\MergeRequest;
use App\Exception\ImportException;
use App\Service\AttachmentMigrator;
use App\Service\BranchSyncService;
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

#[AsCommand(name: 'import-mr')]
class ImportMergeRequestsCommand extends Command
{
    use ImportCommandHelpers;

    public function __construct(
        private ParameterBagInterface $params,
        private GitLabService $gitLabService,
        private GitHubService $gitHubService,
        private GitHubApiClient $gitHubApiClient,
        private BranchSyncService $branchSyncService,
        private AttachmentMigrator $attachmentMigrator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->setDescription('Import merge requests')
            ->setHelp('Imports merge requests from the GitLab repository into GitHub pull requests')
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
                'gitLabUser',
                null,
                InputOption::VALUE_OPTIONAL,
                'The GitLab user/group owning the source repository (e.g. user123)'
            )
            ->addOption(
                'gitLabRepositoryName',
                null,
                InputOption::VALUE_OPTIONAL,
                'The GitLab source repository name, without owner or .git (e.g. project-abc)'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'The maximum number of merge requests to import. Already existing MRs are not counted'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Perform a dry run to see what MRs would be imported but without making any actual change'
            )
            ->addOption(
                'skipSslCertificateVerification',
                null,
                InputOption::VALUE_NONE,
                'Skip SSL certificate verification'
            )
            ->addOption(
                'skipTargetBranchProtection',
                null,
                InputOption::VALUE_NONE,
                'Do not add branch protection to the target branch after the import when it had none. By default, an unprotected target branch is protected (force-push + deletion blocked) once the import finishes.'
            )
            ->addOption(
                'delay',
                null,
                InputOption::VALUE_OPTIONAL,
                "Seconds to pause between each imported merge request, to stay under GitHub's secondary rate limit for write requests. GitHub recommends at least 1s between mutating requests. Fractional values are allowed (e.g. 0.5). Set to 0 to disable.",
                '1'
            )
        ;
    }

    private function microsecondsFromSeconds(float $seconds): int
    {
        return (int) round($seconds * 1_000_000);
    }

    private function sleepBetweenImports(float $seconds): void
    {
        usleep($this->microsecondsFromSeconds($seconds));
    }

    /**
     * @throws ImportException
     * @throws RuntimeException
     * @throws ParameterNotFoundException
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
        $gitLabUser = $this->retrieveParameter($input, $output, 'gitLabUser');
        $gitLabRepositoryName = $this->retrieveParameter($input, $output, 'gitLabRepositoryName');
        $limit = (int) $input->getOption('limit');
        $isDry = $input->getOption('dry-run');
        $skipSslCertificateVerification = $input->getOption('skipSslCertificateVerification');
        $skipTargetBranchProtection = $input->getOption('skipTargetBranchProtection');

        $delayBetweenMRs = max(0.0, (float) $input->getOption('delay'));

        $targetBranch = '';
        $savedProtection = null;
        $addDefaultProtectionAtEnd = false;

        try {
            $output->writeln('Retrieving merge requests from GitLab project #'.$gitLabProjectId);

            $this->gitLabService->init($gitLabToken, $skipSslCertificateVerification);

            if ([] === $mergeRequests = $this->gitLabService->getMergeRequests($gitLabProjectId)) {
                $output->writeln('No merge requests found on GitLab project #'.$gitLabProjectId);

                return Command::SUCCESS;
            }

            $totalMRs = \count($mergeRequests);

            $output->writeln($totalMRs.' merge requests found, importing'.(0 !== $limit ? \sprintf(' (%s max)', $limit) : null));

            $this->gitHubService->init($gitHubToken, $gitHubUserName, $gitHubRepositoryName, $skipSslCertificateVerification);

            $targetBranch = $mergeRequests[0]->targetBranch;

            if (!$isDry) {
                $savedProtection = $this->gitHubApiClient->getBranchProtection($targetBranch);
                if ($savedProtection instanceof BranchProtection) {
                    $output->writeln(\sprintf("<comment>🔓 '%s' is protected, temporarily lifting protection for the import (it will be restored at the end)</comment>", $targetBranch));
                    try {
                        $this->gitHubApiClient->removeBranchProtection($targetBranch);
                    } catch (Exception $protEx) {
                        $savedProtection = null;
                        $output->writeln(\sprintf("<error>⚠️ Could not lift protection on '%s': %s</error>", $targetBranch, $protEx->getMessage()));
                        $output->writeln('<error>   Aborting to avoid a partial migration (the import would fail on force-push).</error>');

                        return Command::FAILURE;
                    }
                } elseif (!$skipTargetBranchProtection) {
                    $addDefaultProtectionAtEnd = true;
                }
            }

            $output->writeln('Synchronizing branches from GitLab to GitHub...');

            $gitLabRepoUrl = \sprintf('https://oauth2:%s@gitlab.com/%s/%s.git', $gitLabToken, $gitLabUser, $gitLabRepositoryName);
            $gitHubRepoUrl = \sprintf('https://%s@github.com/%s/%s.git', $gitHubToken, $gitHubUserName, $gitHubRepositoryName);

            try {
                $result = $this->branchSyncService->syncBranches(
                    $gitLabRepoUrl,
                    $gitHubRepoUrl,
                    $gitHubToken,
                    $skipSslCertificateVerification
                );

                $output->writeln('<info>✅ Branch synchronization complete</info>');
                $output->writeln('   Synced: '.\count($result['synced']).' branches');
                if (!empty($result['failed'])) {
                    $output->writeln('   Failed: '.\count($result['failed']).' branches');
                    foreach ($result['failed'] as $branch => $error) {
                        $output->writeln(\sprintf('      - %s: ', $branch).substr($error, 0, 50).'...');
                    }
                }
            } catch (Exception $syncException) {
                $output->writeln(\sprintf('<error>⚠️ Branch synchronization failed: %s</error>', $syncException->getMessage()));
                $output->writeln('Continuing with merge request import anyway...');
                $output->writeln('');
            }

            $importOk = 0;
            $erroredMRs = [];
            $alreadyImportedMRs = [];

            $progressBar = $this->createProgressBar($output, $totalMRs);
            $progressBar->start();

            $lastMrKey = array_key_last($mergeRequests);

            foreach ($mergeRequests as $mrKey => $mr) {
                $outputLine = [];
                $outputLine[] = \sprintf('MR !%s "%s"', $mr->gitLabIid, $mr->title);
                $imported = false;

                try {
                    if ($this->gitHubService->isPullRequestImported($mr)) {
                        $outputLine[] = '⏩ Already imported, skipping';
                        $alreadyImportedMRs[] = $mr;
                    } else {
                        if (!$isDry) {
                            $mr->description = $this->attachmentMigrator->migrateDescription($mr->description, $gitLabProjectId, $mr->gitLabUrl);
                            $this->gitHubService->importMergeRequest($mr);
                            $outputLine[] = '✅ Imported';
                            $imported = true;
                        } else {
                            $outputLine[] = '✅ Would be imported if no dry run';
                        }

                        ++$importOk;
                    }
                } catch (ImportException $e) {
                    $outputLine[] = \sprintf('🚫 Error (%s)', $e->getMessage());
                    $erroredMRs[] = ['mr' => $mr, 'error' => $e->getMessage()];
                }

                $progressBar->setMessage(implode(' / ', $outputLine));
                $progressBar->advance();

                if ($limit && $importOk >= $limit) {
                    break;
                }

                if ($imported && 0.0 !== $delayBetweenMRs && $mrKey !== $lastMrKey) {
                    $this->sleepBetweenImports($delayBetweenMRs);
                }
            }

            $progressBar->finish();

            if ($limit && $importOk >= $limit) {
                $output->writeln(\sprintf('Limit of %s imported pull requests reached', $limit));
            }

            $output->writeln('');
            $output->writeln('');

            if (!$isDry) {
                try {
                    $gitLabMainHead = $this->gitLabService->getBranchHeadSha($gitLabProjectId, $targetBranch);
                    if ('' !== $gitLabMainHead) {
                        $this->gitHubService->cherryPickTrailingNonMrCommits($gitLabMainHead, $targetBranch);
                    }
                } catch (Exception $trailingEx) {
                    $output->writeln(\sprintf("<error>⚠️ Failed to cherry-pick trailing commits on '%s': %s</error>", $targetBranch, $trailingEx->getMessage()));
                }
            }

            if ($mergeFailures = $this->gitHubService->mergeFailureMessages) {
                $table = new Table($output);
                $table
                    ->setHeaderTitle('PRs created but merge() failed (closed instead of merged)')
                    ->setHeaders(['GitLab MR id', 'Merge error'])
                    ->setRows($this->mapToRows($mergeFailures, static fn (string $id, string $message): array => [$id, $message]));
                $table->render();
                $output->writeln('');
            }

            if ($reviewRequestWarnings = $this->gitHubService->reviewRequestWarnings) {
                $table = new Table($output);
                $table
                    ->setHeaderTitle('PRs created but review request was skipped')
                    ->setHeaders(['GitLab MR id', 'Reason'])
                    ->setRows($this->mapToRows($reviewRequestWarnings, static fn (string $id, string $reason): array => [$id, $reason]));
                $table->render();
                $output->writeln('');
            }

            if ([] !== $erroredMRs) {
                $table = new Table($output);
                $table
                    ->setHeaderTitle('Failed import merge requests')
                    ->setHeaders(['GitLab MR id', 'title', 'Error'])
                    ->setRows(
                        $this->mapToRows(
                            $erroredMRs,
                            static fn (int $key, array $item): array => [
                                '!'.$item['mr']->gitLabIid,
                                $item['mr']->title,
                                $item['error'],
                            ],
                        )
                    );
                $table->render();
            }

            $this->attachmentMigrator->displayWarnings($output);

            $output->writeln('');

            if ([] !== $alreadyImportedMRs) {
                $table = new Table($output);
                $table
                    ->setHeaderTitle('Already imported merge requests')
                    ->setHeaders(['GitLab MR id', 'title'])
                    ->setRows(
                        $this->mapToRows(
                            $alreadyImportedMRs,
                            static fn (int $key, MergeRequest $mr): array => [
                                '!'.$mr->gitLabIid,
                                $mr->title,
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
                    ['Total merge requests', $totalMRs],
                    ['Imported PRs', $importOk],
                    ['Non imported PRs', $totalMRs - $importOk],
                    ['Already imported PRs', \count($alreadyImportedMRs)],
                    ['Failed imports', \count($erroredMRs)],
                ]);
            $table->render();

            return Command::SUCCESS;
        } catch (Exception $exception) {
            $output->writeln('Error: '.$exception->getMessage());

            return Command::FAILURE;
        } finally {
            if ($savedProtection instanceof BranchProtection) {
                $output->writeln('');
                $output->writeln(\sprintf("<comment>🔒 Restoring branch protection on '%s'...</comment>", $targetBranch));
                try {
                    $this->gitHubService->restoreBranchProtection($targetBranch, $savedProtection);
                    $output->writeln(\sprintf("<info>✅ Branch protection restored on '%s'</info>", $targetBranch));
                } catch (Exception $restoreEx) {
                    $output->writeln(\sprintf("<error>⚠️ FAILED to restore branch protection on '%s': %s</error>", $targetBranch, $restoreEx->getMessage()));
                    $output->writeln('<error>   Re-enable protection manually in the GitHub repository settings.</error>');
                }
            } elseif ($addDefaultProtectionAtEnd) {
                $output->writeln('');
                $output->writeln(\sprintf("<comment>🔒 '%s' had no protection, adding a baseline one (force-push + deletion blocked)</comment>", $targetBranch));
                try {
                    $this->gitHubService->protectBranchWithDefaults($targetBranch);
                    $output->writeln(\sprintf("<info>✅ Branch protection added on '%s'</info>", $targetBranch));
                } catch (Exception $protectEx) {
                    $output->writeln(\sprintf("<error>⚠️ FAILED to add branch protection on '%s': %s</error>", $targetBranch, $protectEx->getMessage()));
                    $output->writeln('<error>   You can protect it manually in the GitHub repository settings.</error>');
                }
            }
        }
    }
}
