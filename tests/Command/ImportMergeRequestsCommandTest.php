<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Client\GitHub\GitHubApiClient;
use App\Client\GitHub\Response\BranchProtection;
use App\Client\GitHub\Response\ProtectionToggle;
use App\Command\ImportMergeRequestsCommand;
use App\Entity\MergeRequest;
use App\Exception\ImportException;
use App\Service\AttachmentMigrator;
use App\Service\BranchSyncService;
use App\Service\GitHubService;
use App\Service\GitLabService;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
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

final class ImportMergeRequestsCommandTest extends TestCase
{
    private const GITLAB_URL = 'https://oauth2:gltok@gitlab.com/gluser/glrepo.git';

    private const GITHUB_URL = 'https://ghtok@github.com/ghuser/ghrepo.git';

    private const PAUSE_DELAY = '0.2';

    private const NEVER_REACHED_DELAY = '2';

    /**
     * @throws LogicException
     */
    public function testCommandNameIsImportMr(): void
    {
        $command = $this->makeCommand(self::createStub(GitLabService::class), self::createStub(GitHubService::class), self::createStub(BranchSyncService::class));

        self::assertSame('import-mr', $command->getName());
    }

    private function makeAttachmentMigrator(): AttachmentMigrator
    {
        return new AttachmentMigrator(
            self::createStub(GitLabService::class),
            self::createStub(GitHubService::class),
        );
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReportsTheAttachmentsThatCouldNotBeUploaded(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $migrator = $this->makeAttachmentMigrator();
        $migrator->warnings[] = "'report.pdf' is not an image";

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand(
            $this->makeCommand($gl, $this->ghImportingAll(1), $this->sync(), [], $api, $migrator),
            $this->opts()
        );
        $display = $tester->getDisplay();

        self::assertStringContainsString('Attachments that could not be uploaded to GitHub:', $display);
        self::assertStringContainsString(" - 'report.pdf' is not an image", $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testSaysNothingAboutAttachmentsWhenTheyAllWentThrough(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand(
            $this->makeCommand($gl, $this->ghImportingAll(1), $this->sync(), [], $api),
            $this->opts()
        );

        self::assertStringNotContainsString('Attachments that could not be uploaded', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws LogicException
     */
    private function makeCommand(
        GitLabService $gitLabService,
        GitHubService $gitHubService,
        BranchSyncService $branchSyncService,
        array $params = [],
        ?GitHubApiClient $gitHubApiClient = null,
        ?AttachmentMigrator $attachmentMigrator = null,
    ): ImportMergeRequestsCommand {
        return new ImportMergeRequestsCommand(
            new ParameterBag($params),
            $gitLabService,
            $gitHubService,
            $gitHubApiClient ?? self::createStub(GitHubApiClient::class),
            $branchSyncService,
            $attachmentMigrator ?? $this->makeAttachmentMigrator(),
        );
    }

    /**
     * @param array<string, mixed> $opts
     * @param list<string>         $inputs
     */
    private function runCommand(Command $command, array $opts, array $inputs = [], bool $interactive = true): CommandTester
    {
        $command->setHelperSet(new HelperSet([new QuestionHelper()]));
        $tester = new CommandTester($command);
        if ([] !== $inputs) {
            $tester->setInputs($inputs);
        }

        $tester->execute($opts, ['interactive' => $interactive]);

        return $tester;
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array{CommandTester, float}
     */
    private function runCommandTimed(Command $command, array $opts): array
    {
        $start = hrtime(true);
        $tester = $this->runCommand($command, $opts);

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
            '--gitLabUser' => 'gluser',
            '--gitLabRepositoryName' => 'glrepo',
            '--delay' => '0',
        ], $extra);
    }

    private function mr(int $iid, string $title = 'feat', string $target = 'main'): MergeRequest
    {
        return new MergeRequest(gitLabIid: $iid, title: $title, targetBranch: $target);
    }

    /**
     * @param list<MergeRequest> $mergeRequests
     */
    private function gitLab(array $mergeRequests, string $headSha = ''): GitLabService&MockObject
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::once())->method('init')->with('gltok', false);
        $gl->method('getMergeRequests')->with(123)->willReturn($mergeRequests);
        $gl->method('getBranchHeadSha')->willReturn($headSha);

        return $gl;
    }

    /**
     * @param array{synced: list<string>, failed: array<string, string>, total: int} $result
     */
    private function sync(array $result = ['synced' => [], 'failed' => [], 'total' => 0]): BranchSyncService&MockObject
    {
        $bs = $this->createMock(BranchSyncService::class);
        $bs->expects(self::once())
            ->method('syncBranches')
            ->with(self::GITLAB_URL, self::GITHUB_URL, 'ghtok', false)
            ->willReturn($result);

        return $bs;
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testImportsEverythingSyncsAndAddsBaselineProtectionOnHappyPath(): void
    {
        $gl = $this->gitLab([$this->mr(1), $this->mr(2)], 'gitlab-head');

        $gh = $this->createMock(GitHubService::class);
        $gh->expects(self::once())->method('init')->with('ghtok', 'ghuser', 'ghrepo', false);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->expects(self::exactly(2))->method('importMergeRequest');
        $gh->expects(self::once())->method('cherryPickTrailingNonMrCommits')->with('gitlab-head', 'main');
        $gh->expects(self::once())->method('protectBranchWithDefaults')->with('main');

        $api = $this->createMock(GitHubApiClient::class);
        $api->method('getBranchProtection')->with('main')->willReturn(null);

        $tester = $this->runCommand(
            $this->makeCommand($gl, $gh, $this->sync(['synced' => ['main', 'feat'], 'failed' => [], 'total' => 2]), [], $api),
            $this->opts()
        );
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Retrieving merge requests from GitLab project #123', $display);
        self::assertStringContainsString('2 merge requests found, importing', $display);
        self::assertStringNotContainsString('max)', $display, 'no --limit set → the "(N max)" suffix must not appear, pins the `$limit ? "..." : null` ternary');
        self::assertStringContainsString('Synchronizing branches from GitLab to GitHub...', $display);
        self::assertStringContainsString('Branch synchronization complete', $display);
        self::assertStringContainsString('Synced: 2 branches', $display);
        self::assertStringContainsString('had no protection', $display);
        self::assertStringContainsString('Branch protection added', $display);
        self::assertStringContainsString('Importation result', $display);
        self::assertMatchesRegularExpression('/Total merge requests\s*\|\s*2\b/', $display);
        self::assertMatchesRegularExpression('/Imported PRs\s*\|\s*2\b/', $display);
        self::assertMatchesRegularExpression('/Non imported PRs\s*\|\s*0\b/', $display);
        self::assertStringNotContainsString('Limit of', $display, 'no limit set → no limit-reached message');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDryRunDoesNotImportProtectOrCherryPickAndLabelsResultAccordingly(): void
    {
        $gl = $this->gitLab([$this->mr(1), $this->mr(2)], 'gitlab-head');

        $gh = $this->createMock(GitHubService::class);
        $gh->expects(self::once())->method('init');
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->expects(self::never())->method('importMergeRequest');
        $gh->expects(self::never())->method('cherryPickTrailingNonMrCommits');
        $gh->expects(self::never())->method('protectBranchWithDefaults');

        $api = $this->createMock(GitHubApiClient::class);
        $api->expects(self::never())->method('getBranchProtection');

        $tester = $this->runCommand(
            $this->makeCommand($gl, $gh, $this->sync(), [], $api),
            $this->opts(['--dry-run' => true])
        );
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would be imported if no dry run', $display);
        self::assertStringContainsString('Dry run result', $display);
        self::assertStringNotContainsString('Importation result', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReportsNothingToDoWhenNoMergeRequests(): void
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::once())->method('init');
        $gl->method('getMergeRequests')->willReturn([]);

        $tester = $this->runCommand(
            $this->makeCommand($gl, self::createStub(GitHubService::class), self::createStub(BranchSyncService::class)),
            $this->opts()
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No merge requests found on GitLab project #123', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testLiftsAndRestoresExistingProtection(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $gh = $this->createMock(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->expects(self::once())->method('restoreBranchProtection')->with('main', new BranchProtection(enforceAdmins: new ProtectionToggle(true)));
        $gh->expects(self::never())->method('protectBranchWithDefaults');

        $api = $this->createMock(GitHubApiClient::class);
        $api->method('getBranchProtection')->with('main')->willReturn(new BranchProtection(enforceAdmins: new ProtectionToggle(true)));
        $api->expects(self::once())->method('removeBranchProtection')->with('main');

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts());
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('temporarily lifting protection', $display);
        self::assertStringContainsString('Restoring branch protection', $display);
        self::assertStringContainsString('Branch protection restored', $display);

        self::assertMatchesRegularExpression('/\r?\n\r?\n🔒 Restoring branch protection/u', $display, "a blank line (writeln('')) must precede the branch-protection restore notice");
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testAbortsWhenExistingProtectionCannotBeLifted(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $gh = $this->createMock(GitHubService::class);
        $gh->expects(self::never())->method('importMergeRequest');
        $gh->expects(self::never())->method('restoreBranchProtection');

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(new BranchProtection(enforceAdmins: new ProtectionToggle(true)));
        $api->method('removeBranchProtection')->willThrowException(new RuntimeException('no admin scope'));

        $bs = $this->createMock(BranchSyncService::class);
        $bs->expects(self::never())->method('syncBranches');

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $bs, [], $api), $this->opts());
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Could not lift protection', $display);
        self::assertStringContainsString('Aborting', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testSkipsAddingProtectionWhenFlagSet(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $gh = $this->createMock(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->expects(self::never())->method('protectBranchWithDefaults');

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts(['--skipTargetBranchProtection' => true]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('adding a baseline', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReportsFailureWhenRestoringProtectionThrows(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $gh = self::createStub(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->method('restoreBranchProtection')->willThrowException(new RuntimeException('restore boom'));

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(new BranchProtection(enforceAdmins: new ProtectionToggle(true)));

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts());

        self::assertStringContainsString('FAILED to restore branch protection', $tester->getDisplay());
        self::assertStringContainsString('Re-enable protection manually', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReportsFailureWhenAddingBaselineProtectionThrows(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $gh = self::createStub(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->method('protectBranchWithDefaults')->willThrowException(new RuntimeException('add boom'));

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts());

        self::assertStringContainsString('FAILED to add branch protection', $tester->getDisplay());
        self::assertStringContainsString('protect it manually', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testContinuesWhenBranchSyncThrows(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $gh = $this->createMock(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->expects(self::once())->method('importMergeRequest');

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $bs = self::createStub(BranchSyncService::class);
        $bs->method('syncBranches')->willThrowException(new RuntimeException('clone denied'));

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $bs, [], $api), $this->opts());
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Branch synchronization failed: clone denied', $display);
        self::assertStringContainsString('Continuing with merge request import anyway', $display);

        self::assertMatchesRegularExpression('/Continuing with merge request import anyway\.\.\.\r?\n\r?\n/', $display, 'a blank line (writeln(\'\')) must follow the "continuing" notice after a sync failure');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReportsFailedBranchesFromSync(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $gh = self::createStub(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $bs = $this->sync([
            'synced' => ['main'],
            'failed' => ['feat' => 'remote rejected the push because the branch is protected here'],
            'total' => 2,
        ]);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $bs, [], $api), $this->opts());
        $display = $tester->getDisplay();

        self::assertStringContainsString('Synced: 1 branches', $display);
        self::assertStringContainsString('Failed: 1 branches', $display);
        self::assertStringContainsString('feat: remote rejected the push because the branch is pro...', $display);
        self::assertStringNotContainsString('protected here', $display, 'the message beyond 50 chars must be truncated');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testCollectsPerMergeRequestImportErrors(): void
    {
        $gl = $this->gitLab([$this->mr(42, 'broken feature')]);

        $gh = self::createStub(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->method('importMergeRequest')->willThrowException(new ImportException('branch missing'));

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts());
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Failed import merge requests', $display);
        self::assertMatchesRegularExpression('/GitLab MR id\s*\|\s*title\s*\|\s*Error/', $display);
        self::assertMatchesRegularExpression('/!42\s*\|\s*broken feature\s*\|\s*branch missing/', $display);
        self::assertMatchesRegularExpression('/Failed imports\s*\|\s*1\b/', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testSkipsAlreadyImportedMergeRequests(): void
    {
        $gl = $this->gitLab([$this->mr(7, 'done feature already present on GitHub')]);

        $gh = $this->createMock(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(true);
        $gh->expects(self::never())->method('importMergeRequest');

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts());
        $display = $tester->getDisplay();

        self::assertStringContainsString('Already imported merge requests', $display);
        self::assertMatchesRegularExpression('/GitLab MR id\s*\|\s*title/', $display);
        self::assertMatchesRegularExpression('/!7\s*\|\s*done feature already present on GitHub/', $display);
        self::assertMatchesRegularExpression('/Already imported PRs\s*\|\s*1\b/', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testStopsAtImportLimit(): void
    {
        $gl = $this->gitLab([$this->mr(1), $this->mr(2), $this->mr(3)]);

        $gh = $this->createMock(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->expects(self::once())->method('importMergeRequest');

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts(['--limit' => '1']));
        $display = $tester->getDisplay();

        self::assertStringContainsString('(1 max)', $display);
        self::assertStringContainsString('Limit of 1 imported pull requests reached', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testCoercesNonNumericLimitToInteger(): void
    {
        $gl = $this->gitLab([$this->mr(1), $this->mr(2), $this->mr(3)]);

        $gh = $this->createMock(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->expects(self::exactly(2))->method('importMergeRequest');

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts(['--limit' => '2x']));
        $display = $tester->getDisplay();

        self::assertStringContainsString('(2 max)', $display);
        self::assertStringContainsString('Limit of 2 imported pull requests reached', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testRendersMergeFailureAndReviewRequestWarningTables(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);

        $gh = self::createStub(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);

        $gh->mergeFailureMessages = [
            '!1' => 'protected branch rejected the forced merge push here',
            '!2' => 'second merge failure on another pull request',
        ];
        $gh->reviewRequestWarnings = [
            '!1' => 'the requested reviewer is also the PR author',
            '!2' => 'the second reviewer could not be resolved',
        ];

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts());
        $display = $tester->getDisplay();

        self::assertStringContainsString('PRs created but merge() failed (closed instead of merged)', $display);
        self::assertMatchesRegularExpression('/GitLab MR id\s*\|\s*Merge error/', $display);
        self::assertMatchesRegularExpression('/!1\s*\|\s*protected branch/', $display);
        self::assertMatchesRegularExpression('/!2\s*\|\s*second merge failure/', $display);
        self::assertStringContainsString('PRs created but review request was skipped', $display);
        self::assertMatchesRegularExpression('/GitLab MR id\s*\|\s*Reason/', $display);
        self::assertMatchesRegularExpression('/!1\s*\|\s*the requested reviewer/', $display);
        self::assertMatchesRegularExpression('/!2\s*\|\s*the second reviewer/', $display);
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testExactBlankSpacingBetweenTheProgressBarAndTheResultTable(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);
        $gh = $this->ghImportingAll(1);
        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $command = $this->makeCommand($gl, $gh, $this->sync(), [], $api);
        $tester = $this->runCommand($command, $this->opts());
        $display = $tester->getDisplay();

        self::assertMatchesRegularExpression('/✅ Imported(?:\r?\n){4}\+-+ Importation result/u', $display, "exactly three blank lines (four writeln('') calls) must sit between the bar and the result table");
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testBlankLineSeparatesEachReportSection(): void
    {
        $gl = $this->gitLab([$this->mr(1, 'ok'), $this->mr(2, 'already imported'), $this->mr(3, 'err')]);

        $gh = self::createStub(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturnCallback(static fn (MergeRequest $mr): bool => 2 === $mr->gitLabIid);
        $gh->method('importMergeRequest')->willReturnCallback(static function (MergeRequest $mr): void {
            if (3 === $mr->gitLabIid) {
                throw new ImportException('kaboom');
            }
        });
        $gh->mergeFailureMessages = ['!1' => 'merge failed'];
        $gh->reviewRequestWarnings = ['!1' => 'review skipped'];

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts());
        $display = $tester->getDisplay();

        $blank = '\r?\n\r?\n';
        self::assertMatchesRegularExpression('/'.$blank.'\+- PRs created but revie/', $display, 'a blank line must separate the merge-failure table from the review-request table');
        self::assertMatchesRegularExpression('/'.$blank.'\+- Failed import merge/', $display, 'a blank line must separate the review-request table from the failed-imports table');
        self::assertMatchesRegularExpression('/'.$blank.'\+- Already import/', $display, 'a blank line must separate the failed-imports table from the already-imported table');
        self::assertMatchesRegularExpression('/'.$blank.'\+-+ Importation result/', $display, 'a blank line must separate the already-imported table from the result table');
        self::assertMatchesRegularExpression('/'.$blank."🔒 'main' had no protection/u", $display, 'a blank line must separate the result table from the branch-protection notice');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReportsTrailingCherryPickFailure(): void
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::once())->method('init');
        $gl->method('getMergeRequests')->willReturn([$this->mr(1)]);
        $gl->method('getBranchHeadSha')->willThrowException(new RuntimeException('gitlab down'));

        $gh = self::createStub(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts());

        self::assertStringContainsString("Failed to cherry-pick trailing commits on 'main'", $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDoesNotCherryPickWhenGitLabMainHeadIsEmpty(): void
    {
        $gl = $this->gitLab([$this->mr(1)], '');

        $gh = $this->createMock(GitHubService::class);
        $gh->expects(self::never())->method('cherryPickTrailingNonMrCommits');

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $tester = $this->runCommand($this->makeCommand($gl, $gh, $this->sync(), [], $api), $this->opts());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testReturnsFailureWhenRetrievingMergeRequestsThrows(): void
    {
        $gl = self::createStub(GitLabService::class);
        $gl->method('init')->willThrowException(new ImportException('bad token'));

        $tester = $this->runCommand(
            $this->makeCommand($gl, self::createStub(GitHubService::class), self::createStub(BranchSyncService::class)),
            $this->opts()
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Error: bad token', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testResolvesMissingOptionFromContainerParameter(): void
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::once())->method('init');
        $gl->method('getMergeRequests')->with(999)->willReturn([]);

        $command = $this->makeCommand($gl, self::createStub(GitHubService::class), self::createStub(BranchSyncService::class), ['gitLabProjectId' => '999']);
        $opts = $this->opts();
        unset($opts['--gitLabProjectId']);

        $tester = $this->runCommand($command, $opts);

        self::assertStringContainsString('project #999', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testPromptsForMissingOptionAndParameter(): void
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::once())->method('init');
        $gl->method('getMergeRequests')->with(555)->willReturn([]);

        $command = $this->makeCommand($gl, self::createStub(GitHubService::class), self::createStub(BranchSyncService::class));
        $opts = $this->opts();
        unset($opts['--gitLabProjectId']);

        $tester = $this->runCommand($command, $opts, ['555']);

        self::assertStringContainsString('gitLabProjectId? ', $tester->getDisplay(), 'the prompt is "<name>? "');
        self::assertStringContainsString('project #555', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testPromptsWhenContainerParameterIsPresentButEmpty(): void
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::once())->method('init');
        $gl->method('getMergeRequests')->with(777)->willReturn([]);

        $command = $this->makeCommand(
            $gl,
            self::createStub(GitHubService::class),
            self::createStub(BranchSyncService::class),
            ['gitLabProjectId' => '']
        );
        $opts = $this->opts();
        unset($opts['--gitLabProjectId']);

        $tester = $this->runCommand($command, $opts, ['777']);

        self::assertStringContainsString('gitLabProjectId? ', $tester->getDisplay(), 'an empty container parameter must NOT short-circuit the prompt');
        self::assertStringContainsString('project #777', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testAnEmptyOptionFallsThroughToTheContainerParameter(): void
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::once())->method('init');
        $gl->expects(self::once())->method('getMergeRequests')->with(444)->willReturn([]);

        $command = $this->makeCommand(
            $gl,
            self::createStub(GitHubService::class),
            self::createStub(BranchSyncService::class),
            ['gitLabProjectId' => '444']
        );

        $tester = $this->runCommand($command, $this->opts(['--gitLabProjectId' => '']));

        self::assertStringContainsString('project #444', $tester->getDisplay());
        self::assertStringNotContainsString('project #0', $tester->getDisplay(), 'an empty option must not be cast to 0');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testAnEmptyOptionWithNoContainerParameterPrompts(): void
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::once())->method('init');
        $gl->expects(self::once())->method('getMergeRequests')->with(666)->willReturn([]);

        $command = $this->makeCommand(
            $gl,
            self::createStub(GitHubService::class),
            self::createStub(BranchSyncService::class)
        );

        $tester = $this->runCommand($command, $this->opts(['--gitLabProjectId' => '']), ['666']);

        self::assertStringContainsString('gitLabProjectId? ', $tester->getDisplay(), 'an empty option must not short-circuit the prompt');
        self::assertStringContainsString('project #666', $tester->getDisplay());
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testPromptsWhenContainerParameterIsNotAScalar(): void
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::once())->method('init');
        $gl->method('getMergeRequests')->with(888)->willReturn([]);

        $command = $this->makeCommand(
            $gl,
            self::createStub(GitHubService::class),
            self::createStub(BranchSyncService::class),
            ['gitLabProjectId' => ['999']]
        );
        $opts = $this->opts();
        unset($opts['--gitLabProjectId']);

        $tester = $this->runCommand($command, $opts, ['888']);

        self::assertStringContainsString('gitLabProjectId? ', $tester->getDisplay(), 'a non-scalar container parameter must NOT short-circuit the prompt');
        self::assertStringContainsString('project #888', $tester->getDisplay());
    }

    /**
     * @throws LogicException
     */
    public function testFailsLoudlyWhenAMissingValueCannotBePromptedForNonInteractively(): void
    {
        $gl = $this->createMock(GitLabService::class);
        $gl->expects(self::never())->method('init');
        $gl->expects(self::never())->method('getMergeRequests');

        $command = $this->makeCommand(
            $gl,
            self::createStub(GitHubService::class),
            self::createStub(BranchSyncService::class)
        );

        $opts = $this->opts();
        unset($opts['--gitLabProjectId']);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('No value provided for "gitLabProjectId"');

        $this->runCommand($command, $opts, [], false);
    }

    private function ghImportingAll(int $importCount): GitHubService&MockObject
    {
        $gh = $this->createMock(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->expects(self::exactly($importCount))->method('importMergeRequest');

        return $gh;
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testPausesBetweenTwoImportedMergeRequests(): void
    {
        $gl = $this->gitLab([$this->mr(1), $this->mr(2)]);
        $command = $this->makeCommand($gl, $this->ghImportingAll(2), $this->sync());

        [$tester, $elapsedMs] = $this->runCommandTimed($command, $this->opts(['--delay' => self::PAUSE_DELAY]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertGreaterThan(150, $elapsedMs, 'two imports must be separated by a real 200ms pause, the fractional delay included');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDoesNotPauseAfterTheLastImport(): void
    {
        $gl = $this->gitLab([$this->mr(1)]);
        $command = $this->makeCommand($gl, $this->ghImportingAll(1), $this->sync());

        [$tester, $elapsedMs] = $this->runCommandTimed($command, $this->opts(['--delay' => self::NEVER_REACHED_DELAY]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertLessThan(500, $elapsedMs, 'the only merge request is the last one: the command must not wait before finishing');
    }

    /**
     * @throws LogicException
     */
    public function testTheDefaultDelayIsOneSecond(): void
    {
        $default = $this->newRealCommand()->getDefinition()->getOption('delay')->getDefault();

        self::assertSame('1', $default, 'GitHub asks for at least a second between writes: that must be the default, not something to opt into');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testTheDefaultDelayReallyPausesForASecond(): void
    {
        $opts = $this->opts();
        unset($opts['--delay']);

        $gl = $this->gitLab([$this->mr(1), $this->mr(2)]);
        $command = $this->makeCommand($gl, $this->ghImportingAll(2), $this->sync());

        [$tester, $elapsedMs] = $this->runCommandTimed($command, $opts);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertGreaterThan(900, $elapsedMs, 'run with no --delay, the pause between two imports must really last the default second');
    }

    /**
     * @return array<string, array{float, int}>
     */
    public static function microsecondConversionProvider(): array
    {
        return [
            'one second' => [1.0, 1_000_000],
            'half a second' => [0.5, 500_000],
            'fraction below .5 rounds down' => [0.0000014, 1],
            'fraction at .5 rounds up' => [0.0000015, 2],
            'zero' => [0.0, 0],
            'three seconds' => [3.0, 3_000_000],
        ];
    }

    /**
     * @throws ReflectionException
     * @throws LogicException
     */
    #[DataProvider('microsecondConversionProvider')]
    public function testMicrosecondsFromSecondsConvertsExactly(float $seconds, int $expectedMicroseconds): void
    {
        $ref = new ReflectionMethod($this->newRealCommand(), 'microsecondsFromSeconds');

        self::assertSame($expectedMicroseconds, $ref->invoke($this->newRealCommand(), $seconds));
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDelayZeroDisablesThrottling(): void
    {
        $gl = $this->gitLab([$this->mr(1), $this->mr(2), $this->mr(3)]);
        $command = $this->makeCommand($gl, $this->ghImportingAll(3), $this->sync());

        [$tester, $elapsedMs] = $this->runCommandTimed($command, $this->opts(['--delay' => '0']));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertLessThan(500, $elapsedMs, '--delay 0 must disable the pause entirely');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testNegativeDelayIsClampedToNoThrottling(): void
    {
        $gl = $this->gitLab([$this->mr(1), $this->mr(2)]);
        $command = $this->makeCommand($gl, $this->ghImportingAll(2), $this->sync());

        [$tester, $elapsedMs] = $this->runCommandTimed($command, $this->opts(['--delay' => '-3']));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertLessThan(500, $elapsedMs, 'a negative --delay must be clamped to 0: no wait');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testDryRunDoesNotThrottle(): void
    {
        $gl = $this->gitLab([$this->mr(1), $this->mr(2)]);
        $gh = $this->createMock(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(false);
        $gh->expects(self::never())->method('importMergeRequest');

        $command = $this->makeCommand($gl, $gh, $this->sync());

        [$tester, $elapsedMs] = $this->runCommandTimed($command, $this->opts([
            '--dry-run' => true,
            '--delay' => self::NEVER_REACHED_DELAY,
        ]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertLessThan(500, $elapsedMs, 'a dry run writes nothing, so it has no rate limit to respect');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testSkippedMergeRequestsDoNotThrottle(): void
    {
        $gl = $this->gitLab([$this->mr(1), $this->mr(2)]);
        $gh = $this->createMock(GitHubService::class);
        $gh->method('isPullRequestImported')->willReturn(true);
        $gh->expects(self::never())->method('importMergeRequest');

        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $command = $this->makeCommand($gl, $gh, $this->sync(), [], $api);

        [$tester, $elapsedMs] = $this->runCommandTimed($command, $this->opts(['--delay' => self::NEVER_REACHED_DELAY]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertLessThan(500, $elapsedMs, 'skipped merge requests perform no write, so they earn no pause');
    }

    /**
     * @throws LogicException
     */
    private function newRealCommand(): ImportMergeRequestsCommand
    {
        return $this->makeCommand(
            self::createStub(GitLabService::class),
            self::createStub(GitHubService::class),
            self::createStub(BranchSyncService::class),
        );
    }

    /**
     * @throws ReflectionException
     * @throws LogicException
     */
    public function testCreateProgressBarUsesTheConfiguredBarCharacters(): void
    {
        $command = $this->newRealCommand();
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);

        $ref = new ReflectionMethod($command, 'createProgressBar');
        /** @var ProgressBar $progressBar */
        $progressBar = $ref->invoke($command, $output, 10);
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
        $filledCount = substr_count($lastFrame, '█');

        self::assertGreaterThan(1, $filledCount, 'both the filled cells (setBarCharacter) and the cursor (setProgressCharacter) must render as █, '
        .'too few █ means one of the two calls was skipped');

        self::assertStringContainsString('▒', $rendered, 'the empty cells must use ▒ (setEmptyBarCharacter)');

        self::assertStringNotContainsString('=', $rendered, 'no default bar character (=) — setBarCharacter must run');
        self::assertStringNotContainsString('>', $rendered, 'no default progress character (>) — setProgressCharacter must run');
        self::assertStringNotContainsString('-', $rendered, 'no default empty character (-) — setEmptyBarCharacter must run');

        self::assertStringNotContainsString('%message%', $rendered, 'setMessage() must initialise the message placeholder');
    }

    /**
     * @throws LogicException
     */
    private function commandWithObservableProgressBar(
        GitLabService $gl,
        GitHubService $gh,
        GitHubApiClient $api,
        BranchSyncService $bs,
        BufferedOutput $capturedOutput,
    ): ImportMergeRequestsCommand {
        return new class(new ParameterBag([]), $gl, $gh, $api, $bs, $this->makeAttachmentMigrator(), $capturedOutput) extends ImportMergeRequestsCommand {
            public function __construct(
                ParameterBag $params,
                GitLabService $gitLabService,
                GitHubService $gitHubService,
                GitHubApiClient $gitHubApiClient,
                BranchSyncService $branchSyncService,
                AttachmentMigrator $attachmentMigrator,
                private BufferedOutput $capturedOutput,
            ) {
                parent::__construct($params, $gitLabService, $gitHubService, $gitHubApiClient, $branchSyncService, $attachmentMigrator);
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
    public function testProgressBarIsStartedAndAdvancesOncePerImportedMergeRequest(): void
    {
        $captured = new BufferedOutput();
        $gl = $this->gitLab([$this->mr(1, 'first'), $this->mr(2, 'second'), $this->mr(3, 'third')]);
        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $command = $this->commandWithObservableProgressBar($gl, $this->ghImportingAll(3), $api, $this->sync(), $captured);
        $tester = $this->runCommand($command, $this->opts());
        $bar = $captured->fetch();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        self::assertStringContainsString('0/3', $bar, 'start() must render the initial frame');

        self::assertStringContainsString('MR !1 "first"', $bar, 'advance() must render the frame for MR #1');
        self::assertStringContainsString('MR !2 "second"', $bar, 'advance() must render the frame for MR #2');
        self::assertStringContainsString('MR !3 "third"', $bar, 'advance() must render the frame for MR #3');
    }

    /**
     * @throws RuntimeException
     * @throws LogicException
     */
    public function testProgressBarIsFinishedEvenWhenTheLoopBreaksEarlyOnLimit(): void
    {
        $captured = new BufferedOutput();
        $gl = $this->gitLab([$this->mr(1), $this->mr(2), $this->mr(3)]);
        $api = self::createStub(GitHubApiClient::class);
        $api->method('getBranchProtection')->willReturn(null);

        $command = $this->commandWithObservableProgressBar($gl, $this->ghImportingAll(1), $api, $this->sync(), $captured);
        $tester = $this->runCommand($command, $this->opts(['--limit' => '1']));
        $bar = $captured->fetch();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('3/3', $bar, 'finish() must complete the bar to its max even after an early break');
    }
}
