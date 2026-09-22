<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImportCommand;
use App\Entity\Issue;
use App\Entity\IssueState;
use App\Exception\ImportException;
use App\Service\AttachmentMigrator;
use App\Service\GitHubService;
use App\Service\GitLabService;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ImportCommandTest extends TestCase
{
    private const PAUSE_DELAY_MS = '200';

    private const NEVER_REACHED_DELAY_MS = '2000';

    /**
     * @throws LogicException
     */
    public function testCommandNameIsImport(): void
    {
        self::assertSame('import', $this->makeCommand(self::createStub(GitLabService::class), self::createStub(GitHubService::class))->getName());
    }

    /**
     * @throws LogicException
     */
    public function testTheDefaultImportDelayIsThreeSeconds(): void
    {
        $command = $this->makeCommand(self::createStub(GitLabService::class), self::createStub(GitHubService::class));

        self::assertSame('3000', $command->getDefinition()->getOption('gitHubImportDelayMs')->getDefault());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testImportsEveryIssueOnHappyPath(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1, 'First'), $this->issue(2, 'Second')]);

        $gitHubService = $this->createMock(GitHubService::class);
        $gitHubService->expects(self::once())->method('init')->with('ghtok', 'ghuser', 'ghrepo', false);
        $gitHubService->method('isImported')->willReturn(false);
        $gitHubService->expects(self::exactly(2))->method('importIssue');

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Importation result', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Total issues\s+\|\s+2/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Imported issues\s+\|\s+2/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Non imported issues\s+\|\s+0/', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testAnnouncesTheProjectItReadsAndTheNumberOfIssuesItFound(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1), $this->issue(2)]);

        $tester = $this->runCommand($this->makeCommand($gitLabService, $this->gitHub()), $this->opts());

        self::assertStringContainsString('Retrieving issues from GitLab project #123', $tester->getDisplay());
        self::assertStringContainsString('2 issues found, importing', $tester->getDisplay());
        self::assertStringNotContainsString('max)', $tester->getDisplay(), 'no limit was given, so no limit must be announced');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testAnnouncesTheLimitItWasGiven(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1), $this->issue(2)]);

        $tester = $this->runCommand($this->makeCommand($gitLabService, $this->gitHub()), $this->opts(['--limit' => '1']));

        self::assertStringContainsString('2 issues found, importing (1 max)', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReportsNothingToDoWhenTheProjectHasNoIssue(): void
    {
        $gitLabService = $this->gitLab([]);

        $gitHubService = $this->createMock(GitHubService::class);
        $gitHubService->expects(self::never())->method('init');
        $gitHubService->expects(self::never())->method('importIssue');

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No issues found on GitLab project #123', $tester->getDisplay());
        self::assertStringNotContainsString('Importation result', $tester->getDisplay(), 'nothing was imported, so no result table must be rendered');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDryRunCountsTheIssuesWithoutImportingThem(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1), $this->issue(2)]);

        $gitHubService = $this->createMock(GitHubService::class);
        $gitHubService->method('isImported')->willReturn(false);
        $gitHubService->expects(self::never())->method('importIssue');

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts(['--dry-run' => true]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run result', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Imported issues\s+\|\s+2/', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testSkipsTheIssuesAlreadyOnGitHubAndListsThem(): void
    {
        $gitLabService = $this->gitLab([$this->issue(7, 'Already there')]);

        $gitHubService = $this->createMock(GitHubService::class);
        $gitHubService->method('isImported')->willReturn(true);
        $gitHubService->expects(self::never())->method('importIssue');

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Already imported issues', $tester->getDisplay());
        self::assertMatchesRegularExpression('/\|\s+GitLab issue id\s+\|\s+title\s+\|/', $tester->getDisplay(), 'the already-imported table must keep both headers');
        self::assertMatchesRegularExpression('/\|\s+#7\s+\|\s+Already there\s+\|/', $tester->getDisplay(), 'the already-imported table must list the issue by id and title');
        self::assertMatchesRegularExpression('/Already imported issues\s+\|\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Imported issues\s+\|\s+0/', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testCollectsPerIssueImportErrorsAndCarriesOn(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1, 'Breaks'), $this->issue(2, 'Fine')]);

        $gitHubService = $this->gitHub();
        $gitHubService->method('importIssue')->willReturnCallback(
            static function (Issue $issue): void {
                if ('Breaks' === $issue->title) {
                    throw new ImportException('422 Unprocessable');
                }
            }
        );

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Failed import issues', $tester->getDisplay());
        self::assertMatchesRegularExpression('/\|\s+GitLab issue id\s+\|\s+title\s+\|/', $tester->getDisplay(), 'the failed-imports table must keep both headers');
        self::assertMatchesRegularExpression('/\|\s+#1\s+\|\s+Breaks\s+\|/', $tester->getDisplay(), 'the failed-imports table must list the issue by id and title');
        self::assertMatchesRegularExpression('/Failed imports\s+\|\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Imported issues\s+\|\s+1/', $tester->getDisplay(), 'the issue that worked must still be counted');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testStopsAtTheImportLimitAndSaysSo(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1), $this->issue(2), $this->issue(3)]);

        $gitHubService = $this->createMock(GitHubService::class);
        $gitHubService->method('isImported')->willReturn(false);
        $gitHubService->expects(self::once())->method('importIssue');

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts(['--limit' => '1']));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Limit of 1 imported issues reached', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Non imported issues\s+\|\s+2/', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testAnUnreadableLimitImportsEverything(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1), $this->issue(2)]);

        $gitHubService = $this->createMock(GitHubService::class);
        $gitHubService->method('isImported')->willReturn(false);
        $gitHubService->expects(self::exactly(2))->method('importIssue');

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts(['--limit' => 'not a number']));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('Limit of', $tester->getDisplay());
        self::assertStringNotContainsString('max)', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testForwardsTheSkipSslFlagToBothServices(): void
    {
        $gitLabService = $this->createMock(GitLabService::class);
        $gitLabService->expects(self::once())->method('init')->with('gltok', true);
        $gitLabService->method('getIssues')->willReturn([$this->issue(1)]);

        $gitHubService = $this->createMock(GitHubService::class);
        $gitHubService->expects(self::once())->method('init')->with('ghtok', 'ghuser', 'ghrepo', true);
        $gitHubService->method('isImported')->willReturn(false);

        $tester = $this->runCommand(
            $this->makeCommand($gitLabService, $gitHubService),
            $this->opts(['--skipSslCertificateVerification' => true])
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReturnsFailureWhenRetrievingIssuesThrows(): void
    {
        $gitLabService = self::createStub(GitLabService::class);
        $gitLabService->method('getIssues')->willThrowException(new ImportException('GitLab is down'));

        $tester = $this->runCommand($this->makeCommand($gitLabService, self::createStub(GitHubService::class)), $this->opts());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Error: GitLab is down', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReturnsFailureWhenConnectingToGitHubThrows(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1)]);

        $gitHubService = self::createStub(GitHubService::class);
        $gitHubService->method('init')->willThrowException(new ImportException('Bad credentials'));

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Error: Bad credentials', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testMigratesTheAttachmentsOfAnIssueBeforeImportingIt(): void
    {
        $issue = $this->issue(1, 'With a screenshot');
        $issue->description = '![shot](/uploads/secret/shot.png)';
        $issue->gitLabUrl = 'https://gitlab.com/group/project/-/issues/1';

        $gitLabService = $this->gitLab([$issue]);

        $gitHubService = $this->createMock(GitHubService::class);
        $gitHubService->method('isImported')->willReturn(false);
        $gitHubService->expects(self::once())->method('importIssue')->with(self::callback(
            static fn (Issue $imported): bool => '![shot](https://github.com/user-attachments/assets/uuid)' === $imported->description
        ));

        $migrator = $this->createMock(AttachmentMigrator::class);
        $migrator->expects(self::once())->method('migrateDescription')
            ->with('![shot](/uploads/secret/shot.png)', 123, 'https://gitlab.com/group/project/-/issues/1')
            ->willReturn('![shot](https://github.com/user-attachments/assets/uuid)');

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService, migrator: $migrator), $this->opts());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDoesNotTouchAttachmentsOnADryRun(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1)]);

        $migrator = $this->createMock(AttachmentMigrator::class);
        $migrator->expects(self::never())->method('migrateDescription');

        $tester = $this->runCommand(
            $this->makeCommand($gitLabService, $this->gitHub(), migrator: $migrator),
            $this->opts(['--dry-run' => true])
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDoesNotTouchAttachmentsOfAnAlreadyImportedIssue(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1)]);

        $migrator = $this->createMock(AttachmentMigrator::class);
        $migrator->expects(self::never())->method('migrateDescription');

        $tester = $this->runCommand(
            $this->makeCommand($gitLabService, $this->gitHub(alreadyImported: true), migrator: $migrator),
            $this->opts()
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReportsTheAttachmentsThatCouldNotBeUploaded(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1)]);

        $migrator = new AttachmentMigrator(self::createStub(GitLabService::class), self::createStub(GitHubService::class));
        $migrator->warnings[] = "'report.pdf' is not an image";

        $tester = $this->runCommand(
            $this->makeCommand($gitLabService, $this->gitHub(), migrator: $migrator),
            $this->opts()
        );

        self::assertStringContainsString('Attachments that could not be uploaded to GitHub:', $tester->getDisplay());
        self::assertStringContainsString("- 'report.pdf' is not an image", $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testSaysNothingAboutAttachmentsWhenTheyAllWentThrough(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1)]);

        $migrator = new AttachmentMigrator(self::createStub(GitLabService::class), self::createStub(GitHubService::class));

        $tester = $this->runCommand(
            $this->makeCommand($gitLabService, $this->gitHub(), migrator: $migrator),
            $this->opts()
        );

        self::assertStringNotContainsString('Attachments that could not be uploaded', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testResolvesAMissingOptionFromTheContainerParameter(): void
    {
        $gitLabService = $this->createMock(GitLabService::class);
        $gitLabService->expects(self::once())->method('init')->with('from-container', false);
        $gitLabService->method('getIssues')->willReturn([]);

        $options = $this->opts();
        unset($options['--gitLabToken']);

        $tester = $this->runCommand(
            $this->makeCommand($gitLabService, self::createStub(GitHubService::class), ['gitLabToken' => 'from-container']),
            $options
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testPromptsForAnOptionThatIsNeitherGivenNorConfigured(): void
    {
        $gitLabService = $this->createMock(GitLabService::class);
        $gitLabService->expects(self::once())->method('init')->with('typed-in', false);
        $gitLabService->method('getIssues')->willReturn([]);

        $options = $this->opts();
        unset($options['--gitLabToken']);

        $tester = $this->runCommand(
            $this->makeCommand($gitLabService, self::createStub(GitHubService::class)),
            $options,
            ['typed-in']
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('gitLabToken?', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableContainerParameterProvider(): iterable
    {
        yield 'empty parameter' => [''];
        yield 'parameter that is not a scalar' => [['an', 'array']];
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    #[DataProvider('unusableContainerParameterProvider')]
    public function testPromptsWhenTheContainerParameterCannotBeUsed(mixed $parameter): void
    {
        $gitLabService = $this->createMock(GitLabService::class);
        $gitLabService->expects(self::once())->method('init')->with('typed-in', false);
        $gitLabService->method('getIssues')->willReturn([]);

        $options = $this->opts();
        unset($options['--gitLabToken']);

        $tester = $this->runCommand(
            $this->makeCommand($gitLabService, self::createStub(GitHubService::class), ['gitLabToken' => $parameter]),
            $options,
            ['typed-in']
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testFailsLoudlyWhenAMissingValueCannotBePromptedForNonInteractively(): void
    {
        $gitLabService = $this->createMock(GitLabService::class);
        $gitLabService->expects(self::never())->method('init');
        $gitLabService->expects(self::never())->method('getIssues');

        $options = $this->opts();
        unset($options['--gitLabToken']);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('No value provided for "gitLabToken"');

        $this->runCommand(
            $this->makeCommand($gitLabService, self::createStub(GitHubService::class)),
            $options,
            [],
            false
        );
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testPausesAfterEachImportedIssue(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1), $this->issue(2)]);

        [$tester, $elapsedMs] = $this->runCommandTimed(
            $this->makeCommand($gitLabService, $this->gitHub()),
            $this->opts(['--gitHubImportDelayMs' => self::PAUSE_DELAY_MS])
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertGreaterThan(300, $elapsedMs, 'two imports at 200 ms each must pause for at least 400 ms, minus the clock margin');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDoesNotPauseForAnIssueItSkipped(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1), $this->issue(2)]);

        [$tester, $elapsedMs] = $this->runCommandTimed(
            $this->makeCommand($gitLabService, $this->gitHub(alreadyImported: true)),
            $this->opts(['--gitHubImportDelayMs' => self::NEVER_REACHED_DELAY_MS])
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertLessThan(1000, $elapsedMs, 'nothing was imported, so the 2 s pause must never be reached');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDoesNotPauseOnADryRun(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1), $this->issue(2)]);

        [$tester, $elapsedMs] = $this->runCommandTimed(
            $this->makeCommand($gitLabService, $this->gitHub()),
            $this->opts(['--dry-run' => true, '--gitHubImportDelayMs' => self::NEVER_REACHED_DELAY_MS])
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertLessThan(1000, $elapsedMs, 'a dry run imports nothing, so the 2 s pause must never be reached');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDoesNotPauseForAnIssueThatFailedToImport(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1)]);

        $gitHubService = $this->gitHub();
        $gitHubService->method('importIssue')->willThrowException(new ImportException('nope'));

        [$tester, $elapsedMs] = $this->runCommandTimed(
            $this->makeCommand($gitLabService, $gitHubService),
            $this->opts(['--gitHubImportDelayMs' => self::NEVER_REACHED_DELAY_MS])
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertLessThan(1000, $elapsedMs, 'a failed import must not be followed by a pause');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testShowsTheProgressFromTheFirstIssueToTheLast(): void
    {
        $gitLabService = $this->gitLab([$this->issue(11, 'First'), $this->issue(22, 'Second')]);

        $display = $this->runCommand($this->makeCommand($gitLabService, $this->gitHub()), $this->opts())->getDisplay();

        self::assertStringContainsString('0/2', $display, 'start() must render the initial frame');
        self::assertStringContainsString('2/2', $display, 'advance() and finish() must take the bar to its max');
        self::assertStringContainsString('Issue #22 "Second"', $display, 'setMessage() must name the issue being imported');
        self::assertStringContainsString('✅ Imported', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testShowsThatAnIssueWasSkipped(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1, 'Known')]);

        $display = $this->runCommand($this->makeCommand($gitLabService, $this->gitHub(alreadyImported: true)), $this->opts())->getDisplay();

        self::assertStringContainsString('Issue #1 "Known" / ⏩ Already imported, skipping', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testShowsWhyAnIssueFailed(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1, 'Broken')]);

        $gitHubService = $this->gitHub();
        $gitHubService->method('importIssue')->willThrowException(new ImportException('boom'));

        $display = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts())->getDisplay();

        self::assertStringContainsString('Issue #1 "Broken" / 🚫 Error (boom)', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testSaysWhatWouldHappenOnADryRun(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1)]);

        $display = $this->runCommand($this->makeCommand($gitLabService, $this->gitHub()), $this->opts(['--dry-run' => true]))->getDisplay();

        self::assertStringContainsString('✅ Would be imported if no dry run', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testFinishesTheProgressBarEvenWhenTheLoopBreaksEarlyOnLimit(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1), $this->issue(2), $this->issue(3)]);

        $display = $this->runCommand($this->makeCommand($gitLabService, $this->gitHub()), $this->opts(['--limit' => '1']))->getDisplay();

        self::assertStringContainsString('3/3', $display, 'finish() must complete the bar to its max even after an early break');
    }

    /**
     * @throws ReflectionException
     * @throws LogicException
     */
    public function testTheProgressBarUsesTheConfiguredCharacters(): void
    {
        $command = $this->makeCommand(self::createStub(GitLabService::class), self::createStub(GitHubService::class));
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);

        $progressBar = (new ReflectionMethod($command, 'createProgressBar'))->invoke($command, $output, 10);
        self::assertInstanceOf(ProgressBar::class, $progressBar);

        $progressBar->setRedrawFrequency(1);
        $progressBar->minSecondsBetweenRedraws(0);
        $progressBar->start();
        for ($i = 0; $i < 4; ++$i) {
            $progressBar->advance();
        }

        $rendered = $output->fetch();
        $frames = array_filter(explode("\x1b[1G\x1b[2K", $rendered));
        $lastFrame = end($frames);
        self::assertNotFalse($lastFrame, 'the progress bar must have rendered at least one frame');

        self::assertGreaterThan(
            1,
            substr_count($lastFrame, '█'),
            'both the filled cells (setBarCharacter) and the cursor (setProgressCharacter) must render as █'
        );
        self::assertStringContainsString('▒', $rendered, 'the empty cells must use ▒ (setEmptyBarCharacter)');
        self::assertStringNotContainsString('=', $rendered, 'no default bar character (=) — setBarCharacter must run');
        self::assertStringNotContainsString('>', $rendered, 'no default progress character (>) — setProgressCharacter must run');
        self::assertStringNotContainsString('-', $rendered, 'no default empty character (-) — setEmptyBarCharacter must run');
        self::assertStringNotContainsString('%message%', $rendered, 'setMessage() must initialise the message placeholder');
    }

    /**
     * @return iterable<string, array{IssueState}>
     */
    public static function issueStateProvider(): iterable
    {
        yield 'open issue' => [IssueState::Open];
        yield 'closed issue' => [IssueState::Closed];
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    #[DataProvider('issueStateProvider')]
    public function testImportsAnIssueWhateverItsState(IssueState $state): void
    {
        $issue = $this->issue(1);
        $issue->state = $state;

        $gitLabService = $this->gitLab([$issue]);

        $gitHubService = $this->createMock(GitHubService::class);
        $gitHubService->method('isImported')->willReturn(false);
        $gitHubService->expects(self::once())->method('importIssue')->with(self::callback(
            static fn (Issue $imported): bool => $imported->state === $state
        ));

        $tester = $this->runCommand($this->makeCommand($gitLabService, $gitHubService), $this->opts());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @throws LogicException
     */
    private function commandWithObservableProgressBar(
        GitLabService $gitLabService,
        GitHubService $gitHubService,
        BufferedOutput $capturedOutput,
    ): ImportCommand {
        return new class(new ParameterBag([]), $gitLabService, $gitHubService, $this->passThroughMigrator(), $capturedOutput) extends ImportCommand {
            public function __construct(
                ParameterBag $params,
                GitLabService $gitLabService,
                GitHubService $gitHubService,
                AttachmentMigrator $attachmentMigrator,
                private BufferedOutput $capturedOutput,
            ) {
                parent::__construct($params, $gitLabService, $gitHubService, $attachmentMigrator);
            }

            #[Override]
            protected function createProgressBar(OutputInterface $output, int $max): ProgressBar
            {
                $progressBar = parent::createProgressBar($this->capturedOutput, $max);

                $progressBar->setOverwrite(false);
                $progressBar->setRedrawFrequency(1);
                $progressBar->minSecondsBetweenRedraws(0);
                $progressBar->maxSecondsBetweenRedraws(0);

                return $progressBar;
            }
        };
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testTheProgressBarAdvancesOncePerIssue(): void
    {
        $captured = new BufferedOutput();
        $gitLabService = $this->gitLab([$this->issue(11, 'First'), $this->issue(22, 'Second'), $this->issue(33, 'Third')]);

        $tester = $this->runCommand(
            $this->commandWithObservableProgressBar($gitLabService, $this->gitHub(), $captured),
            $this->opts()
        );
        $bar = $captured->fetch();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('0/3', $bar, 'start() must render the initial frame');
        self::assertStringContainsString('Issue #11 "First"', $bar, 'advance() must render the frame of issue #11');
        self::assertStringContainsString('Issue #22 "Second"', $bar, 'advance() must render the frame of issue #22');
        self::assertStringContainsString('Issue #33 "Third"', $bar, 'advance() must render the frame of issue #33');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testExactBlankSpacingBetweenTheProgressBarAndTheResultTable(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1)]);

        $display = $this->runCommand($this->makeCommand($gitLabService, $this->gitHub()), $this->opts())->getDisplay();

        self::assertMatchesRegularExpression(
            '/✅ Imported(?:\r?\n){4}\+-+ Importation result/u',
            $display,
            "exactly three blank lines (four writeln('') calls) must sit between the bar and the result table"
        );
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testBlankLineSeparatesEachReportSection(): void
    {
        $gitLabService = $this->gitLab([$this->issue(1, 'Broken'), $this->issue(2, 'Known')]);

        $gitHubService = self::createStub(GitHubService::class);
        $gitHubService->method('isImported')->willReturnCallback(static fn (Issue $issue): bool => 'Known' === $issue->title);
        $gitHubService->method('importIssue')->willThrowException(new ImportException('kaboom'));

        $migrator = new AttachmentMigrator(self::createStub(GitLabService::class), self::createStub(GitHubService::class));
        $migrator->warnings[] = "'report.pdf' is not an image";

        $display = $this->runCommand(
            $this->makeCommand($gitLabService, $gitHubService, migrator: $migrator),
            $this->opts()
        )->getDisplay();

        $blank = '\r?\n\r?\n';
        self::assertMatchesRegularExpression('/'.$blank.'\+-+ Failed import issues/', $display, 'a blank line must separate the progress bar from the failed-imports table');
        self::assertMatchesRegularExpression('/'.$blank.'Attachments that could not be uploaded/', $display, 'a blank line must separate the failed-imports table from the attachment warnings');
        self::assertMatchesRegularExpression('/'.$blank.'\+-+ Already imported i/', $display, 'a blank line must separate the attachment warnings from the already-imported table');
        self::assertMatchesRegularExpression('/'.$blank.'\+-+ Importation result/', $display, 'a blank line must separate the already-imported table from the result table');
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function microsecondConversionProvider(): iterable
    {
        yield 'the default delay' => [3000, 3_000_000];
        yield 'a fifth of a second' => [200, 200_000];
        yield 'one millisecond' => [1, 1000];
        yield 'no delay at all' => [0, 0];
    }

    /**
     * @throws ReflectionException
     * @throws LogicException
     */
    #[DataProvider('microsecondConversionProvider')]
    public function testMillisecondsConvertToMicrosecondsExactly(int $milliseconds, int $expectedMicroseconds): void
    {
        $command = $this->makeCommand(self::createStub(GitLabService::class), self::createStub(GitHubService::class));

        self::assertSame(
            $expectedMicroseconds,
            (new ReflectionMethod($command, 'microsecondsFromMilliseconds'))->invoke($command, $milliseconds)
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws LogicException
     */
    private function makeCommand(
        GitLabService $gitLabService,
        GitHubService $gitHubService,
        array $params = [],
        ?AttachmentMigrator $migrator = null,
    ): ImportCommand {
        return new ImportCommand(
            new ParameterBag($params),
            $gitLabService,
            $gitHubService,
            $migrator ?? $this->passThroughMigrator(),
        );
    }

    private function passThroughMigrator(): AttachmentMigrator&Stub
    {
        $migrator = self::createStub(AttachmentMigrator::class);
        $migrator->method('migrateDescription')->willReturnArgument(0);

        return $migrator;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $inputs
     *
     * @throws LogicException
     * @throws RuntimeException
     */
    private function runCommand(Command $command, array $options, array $inputs = [], bool $interactive = true): CommandTester
    {
        $command->setHelperSet(new HelperSet([new QuestionHelper()]));
        $tester = new CommandTester($command);
        if ([] !== $inputs) {
            $tester->setInputs($inputs);
        }

        $tester->execute($options, ['interactive' => $interactive]);

        return $tester;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{CommandTester, float}
     *
     * @throws LogicException
     * @throws RuntimeException
     */
    private function runCommandTimed(Command $command, array $options): array
    {
        $start = hrtime(true);
        $tester = $this->runCommand($command, $options);

        return [$tester, (hrtime(true) - $start) / 1_000_000];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function opts(array $extra = []): array
    {
        return array_merge([
            '--gitLabToken' => 'gltok',
            '--gitLabProjectId' => '123',
            '--gitHubToken' => 'ghtok',
            '--gitHubUserName' => 'ghuser',
            '--gitHubRepositoryName' => 'ghrepo',
            '--gitHubImportDelayMs' => '1',
        ], $extra);
    }

    private function issue(int $gitLabId, string $title = 'An issue'): Issue
    {
        return new Issue(gitLabId: $gitLabId, gitLabIid: $gitLabId, title: $title);
    }

    /**
     * @param list<Issue> $issues
     */
    private function gitLab(array $issues): GitLabService&MockObject
    {
        $gitLabService = $this->createMock(GitLabService::class);
        $gitLabService->expects(self::once())->method('init')->with('gltok', false);
        $gitLabService->method('getIssues')->with(123)->willReturn($issues);

        return $gitLabService;
    }

    private function gitHub(bool $alreadyImported = false): GitHubService&Stub
    {
        $gitHubService = self::createStub(GitHubService::class);
        $gitHubService->method('isImported')->willReturn($alreadyImported);

        return $gitHubService;
    }
}
