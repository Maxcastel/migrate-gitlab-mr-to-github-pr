<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Client\GitHub\GitHubApiClient;
use App\Client\GitHub\GitHubAttachmentApiClient;
use App\Client\GitHub\Response\BranchInfo;
use App\Client\GitHub\Response\BranchProtection;
use App\Client\GitHub\Response\BranchRef;
use App\Client\GitHub\Response\CommitComparison;
use App\Client\GitHub\Response\CreatedCommit;
use App\Client\GitHub\Response\CreatedIssue;
use App\Client\GitHub\Response\CreatedPullRequest;
use App\Client\GitHub\Response\DismissalRestrictions;
use App\Client\GitHub\Response\GitCommit;
use App\Client\GitHub\Response\GitCommitAuthor;
use App\Client\GitHub\Response\IssueSummary;
use App\Client\GitHub\Response\LoginRef;
use App\Client\GitHub\Response\ProtectionToggle;
use App\Client\GitHub\Response\PullRequestSummary;
use App\Client\GitHub\Response\PushRestrictions;
use App\Client\GitHub\Response\RepoCommit;
use App\Client\GitHub\Response\RepoCommitDetail;
use App\Client\GitHub\Response\RequiredPullRequestReviews;
use App\Client\GitHub\Response\RequiredStatusChecks;
use App\Client\GitHub\Response\ShaRef;
use App\Client\GitHub\Response\SlugRef;
use App\Entity\Issue;
use App\Entity\IssueState;
use App\Entity\MergeRequest;
use App\Entity\MergeRequestState;
use App\Exception\Api\GitHubApiException;
use App\Exception\ImportException;
use App\Service\GitHubService;
use App\Tests\Helper\ServiceMockHelper;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use LogicException;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionException;

final class GitHubServiceTest extends TestCase
{
    private GitHubService $service;

    /** @var GitHubApiClient&Stub */
    private GitHubApiClient $api;

    #[Override]
    protected function setUp(): void
    {
        $this->api = self::createStub(GitHubApiClient::class);
        $this->api->method('getUserName')->willReturn('Maxcastel');

        $this->service = new GitHubService($this->api);
    }

    /**
     * @return GitHubApiClient&MockObject
     */
    private function apiMock(): GitHubApiClient
    {
        $mock = $this->createMock(GitHubApiClient::class);
        $mock->method('getUserName')->willReturn('Maxcastel');

        $this->service = new GitHubService($mock);

        return $mock;
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testGetCurrentIssueTitlesReturnsTheInternalMap(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'currentIssueTitles', [12 => 'Foo', 34 => 'Bar']
        );
        self::assertSame([12 => 'Foo', 34 => 'Bar'], $this->service->getCurrentIssueTitles());
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testGetCurrentPullRequestTitlesReturnsTheInternalMap(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'currentPullRequestTitles', [1 => 'PR one', 2 => 'PR two']
        );
        self::assertSame([1 => 'PR one', 2 => 'PR two'], $this->service->getCurrentPullRequestTitles());
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testIsImportedReturnsTrueWhenTitleMatches(): void
    {
        ServiceMockHelper::setPrivateProperty($this->service, 'currentIssueTitles', [
            1 => 'Existing issue',
            2 => 'Another issue',
        ]);

        $issue = new Issue(title: 'Existing issue');
        self::assertTrue($this->service->isImported($issue));
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testIsImportedReturnsFalseWhenTitleDoesNotMatch(): void
    {
        ServiceMockHelper::setPrivateProperty($this->service, 'currentIssueTitles', [
            1 => 'Existing issue',
        ]);

        $issue = new Issue(title: 'Brand new issue');
        self::assertFalse($this->service->isImported($issue));
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testIsPullRequestImportedReturnsTrueWhenSourceBranchMatchesAnExistingPrHead(): void
    {
        ServiceMockHelper::setPrivateProperty($this->service, 'currentPullRequestBranches', [
            10 => 'feat-a',
            11 => 'feat-b',
        ]);

        $mr = new MergeRequest(sourceBranch: 'feat-b');
        self::assertTrue($this->service->isPullRequestImported($mr));
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testIsPullRequestImportedReturnsFalseWhenNoPrHasThatSourceBranch(): void
    {
        ServiceMockHelper::setPrivateProperty($this->service, 'currentPullRequestBranches', [
            10 => 'feat-a',
        ]);

        $mr = new MergeRequest(sourceBranch: 'brand-new-branch');
        self::assertFalse($this->service->isPullRequestImported($mr));
    }

    /**
     * @throws ImportException
     */
    public function testInitConnectsTheApiClientThenLoadsCurrentState(): void
    {
        $api = $this->apiMock();

        $api->expects(self::once())
            ->method('connect')
            ->with('t', 'u', 'r', false);
        $api->method('fetchAllIssues')->willReturn([]);
        $api->method('fetchAllPullRequests')->willReturn([]);

        $this->service->init('t', 'u', 'r');
    }

    /**
     * @throws ImportException
     */
    public function testInitConnectsTheAttachmentClientWithTheSameCredentials(): void
    {
        $this->api->method('fetchAllIssues')->willReturn([]);
        $this->api->method('fetchAllPullRequests')->willReturn([]);

        $attachmentApi = $this->createMock(GitHubAttachmentApiClient::class);
        $attachmentApi->expects(self::once())->method('connect')->with('t', true);

        $service = new GitHubService($this->api, attachmentApi: $attachmentApi);

        $service->init('t', 'u', 'r', true);
    }

    /**
     * @throws ImportException
     */
    public function testInitPopulatesCurrentIssueTitlesFromFetchAllIssues(): void
    {
        $this->api->method('fetchAllIssues')->willReturn([
            new IssueSummary(7, 'First'),
            new IssueSummary(12, 'Second'),
        ]);
        $this->api->method('fetchAllPullRequests')->willReturn([]);

        $this->service->init('t', 'u', 'r');

        self::assertSame([7 => 'First', 12 => 'Second'], $this->service->getCurrentIssueTitles());
    }

    /**
     * @throws ImportException
     */
    public function testInitLeavesIssueTitlesEmptyWhenFetchAllReturnsEmpty(): void
    {
        $this->api->method('fetchAllIssues')->willReturn([]);
        $this->api->method('fetchAllPullRequests')->willReturn([]);

        $this->service->init('t', 'u', 'r');

        self::assertSame([], $this->service->getCurrentIssueTitles());
    }

    /**
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testInitPopulatesPullRequestTitlesAndHeadRefs(): void
    {
        $this->api->method('fetchAllIssues')->willReturn([]);
        $this->api->method('fetchAllPullRequests')->willReturn([
            new PullRequestSummary(1, 'PR one', new BranchRef('feat-a')),
            new PullRequestSummary(2, 'PR two', new BranchRef('feat-b')),
            new PullRequestSummary(3, 'PR three', null),
        ]);

        $this->service->init('t', 'u', 'r');

        self::assertSame(
            [1 => 'PR one', 2 => 'PR two', 3 => 'PR three'],
            $this->service->getCurrentPullRequestTitles()
        );
        $branches = ServiceMockHelper::getPrivateProperty($this->service, 'currentPullRequestBranches');
        self::assertSame(
            [1 => 'feat-a', 2 => 'feat-b'],
            $branches,
            'Head refs must be keyed by PR number and only stored when the listing carried a head'
        );
    }

    /**
     * @throws ImportException
     */
    public function testInitPassesSkipSslFlagToApiClient(): void
    {
        $api = $this->apiMock();

        $api->expects(self::once())
            ->method('connect')
            ->with('t', 'u', 'r', true);
        $api->method('fetchAllIssues')->willReturn([]);
        $api->method('fetchAllPullRequests')->willReturn([]);

        $this->service->init('t', 'u', 'r', true);
    }

    public function testInitWrapsConnectFailureAsImportException(): void
    {
        $upstream = new GitHubApiException('Failed to connect to GitHub: boom', 401);
        $this->api->method('connect')->willThrowException($upstream);

        try {
            $this->service->init('t', 'u', 'r');
            self::fail('init() must wrap a connect failure in an ImportException');
        } catch (ImportException $importException) {
            self::assertSame('Error authenticating at GitHub: Failed to connect to GitHub: boom', $importException->getMessage(), 'the context prefix must precede the upstream message');
            self::assertSame(401, $importException->getCode(), "the upstream exception's code must be propagated");
            self::assertSame($upstream, $importException->getPrevious(), 'the API exception must be chained as previous');
        }
    }

    public function testInitWrapsFetchIssuesFailureAsImportException(): void
    {
        $upstream = new GitHubApiException('GitHub API request failed', 500);
        $this->api->method('fetchAllIssues')->willThrowException($upstream);

        try {
            $this->service->init('t', 'u', 'r');
            self::fail('init() must wrap a fetch-issues failure in an ImportException');
        } catch (ImportException $importException) {
            self::assertSame('Error retrieving issues from GitHub: GitHub API request failed', $importException->getMessage(), 'the context prefix must precede the upstream message');
            self::assertSame(500, $importException->getCode(), "the upstream exception's code must be propagated");
            self::assertSame($upstream, $importException->getPrevious(), 'the API exception must be chained as previous');
        }
    }

    public function testInitWrapsFetchPullRequestsFailureAsImportException(): void
    {
        $upstream = new GitHubApiException('GitHub API request failed', 500);
        $this->api->method('fetchAllIssues')->willReturn([]);
        $this->api->method('fetchAllPullRequests')->willThrowException($upstream);

        try {
            $this->service->init('t', 'u', 'r');
            self::fail('init() must wrap a fetch-pull-requests failure in an ImportException');
        } catch (ImportException $importException) {
            self::assertSame('Error retrieving pull requests from GitHub: GitHub API request failed', $importException->getMessage(), 'the context prefix must precede the upstream message');
            self::assertSame(500, $importException->getCode(), "the upstream exception's code must be propagated");
            self::assertSame($upstream, $importException->getPrevious(), 'the API exception must be chained as previous');
        }
    }

    /**
     * @throws ImportException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws LogicException
     */
    public function testImportIssueCreatesOpenIssueWithCorrectParams(): void
    {
        $api = $this->apiMock();

        $api->expects(self::once())
            ->method('createIssue')
            ->with(
                self::callback(function (array $params): true {
                    $this->assertSame(['title', 'body'], array_keys($params), 'Params must be exactly [title, body] — no assignees when issue has none');
                    $this->assertSame('Test issue', $params['title']);

                    $tsUtc = strtotime('2025-09-08T10:00:00Z');
                    $expectedDate = (new DateTimeImmutable('@'.$tsUtc))
                        ->setTimezone(new DateTimeZone('Europe/Paris'))
                        ->format('d/m/Y H:i:s');
                    $expectedBody = "**Created on GitLab:** {$expectedDate}\n"
                        ."**GitLab Issue:** [#42](https://gitlab.com/x/-/issues/42)\n\n"
                        .'Description text';
                    $this->assertSame($expectedBody, $params['body']);

                    return true;
                })
            )
            ->willReturn(new CreatedIssue(100));

        $issue = new Issue(
            gitLabIid: 42,
            gitLabUrl: 'https://gitlab.com/x/-/issues/42',
            title: 'Test issue',
            description: 'Description text',
            state: IssueState::Open,
            createdAt: strtotime('2025-09-08T10:00:00Z'),
        );

        $this->service->importIssue($issue);
    }

    /**
     * @throws ImportException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws LogicException
     */
    public function testImportIssueFormatsCreationDateInEuropeParisTimezone(): void
    {
        $captured = null;
        $this->api->method('createIssue')
            ->willReturnCallback(static function (array $params) use (&$captured): CreatedIssue {
                $captured = $params;

                return new CreatedIssue(1);
            });

        $issue = new Issue(
            title: 'X',
            createdAt: strtotime('2025-09-08T10:00:00Z'),
        );
        $this->service->importIssue($issue);

        self::assertIsArray($captured, 'createIssue must have been called');
        self::assertIsString($captured['body']);
        self::assertStringContainsString('08/09/2025 12:00:00', $captured['body']);
        self::assertStringNotContainsString('10:00:00', $captured['body'], 'Date must be CEST (12:00), not UTC (10:00)');
    }

    /**
     * @throws ImportException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws LogicException
     */
    public function testImportIssueShowsUnknownDateWhenCreatedAtIsZero(): void
    {
        $captured = null;
        $this->api->method('createIssue')
            ->willReturnCallback(static function (array $params) use (&$captured): CreatedIssue {
                $captured = $params;

                return new CreatedIssue(1);
            });

        $this->service->importIssue(new Issue(title: 'X', createdAt: 0));

        self::assertIsArray($captured, 'createIssue must have been called');
        self::assertIsString($captured['body']);
        self::assertStringContainsString('**Created on GitLab:** Unknown', $captured['body']);
    }

    /**
     * @throws ImportException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws LogicException
     */
    public function testImportIssueAddsAssigneesWhenPresent(): void
    {
        $api = $this->apiMock();

        $api->expects(self::once())
            ->method('createIssue')
            ->with(
                self::callback(function (array $params): true {
                    $this->assertSame(['Maxcastel'], $params['assignees']);

                    return true;
                })
            )
            ->willReturn(new CreatedIssue(100));

        $issue = new Issue(title: 'X', assignees: ['some-gitlab-user']);
        $this->service->importIssue($issue);
    }

    /**
     * @throws ImportException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws LogicException
     */
    public function testImportIssueClosesAfterCreateWhenStateIsClosed(): void
    {
        $api = $this->apiMock();

        $api->expects(self::once())
            ->method('createIssue')
            ->willReturn(new CreatedIssue(100));
        $api->expects(self::once())
            ->method('closeIssue')
            ->with(100);

        $issue = new Issue(title: 'Closed one', state: IssueState::Closed);
        $this->service->importIssue($issue);
    }

    /**
     * @throws ImportException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws LogicException
     */
    public function testImportIssueDoesNotUpdateWhenStateIsOpen(): void
    {
        $api = $this->apiMock();

        $api->expects(self::once())->method('createIssue')->willReturn(new CreatedIssue(100));
        $api->expects(self::never())->method('closeIssue');

        $this->service->importIssue(new Issue(title: 'Open one', state: IssueState::Open));
    }

    /**
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws LogicException
     */
    public function testImportIssueWrapsApiErrorsWithContext(): void
    {
        $upstream = new GitHubApiException('rate limited', 403);
        $this->api->method('createIssue')->willThrowException($upstream);

        try {
            $this->service->importIssue(new Issue(title: 'X'));
            self::fail('importIssue() must wrap API errors in an ImportException');
        } catch (ImportException $importException) {
            self::assertSame('Error adding issue to GitHub: rate limited', $importException->getMessage(), 'the context prefix must precede the upstream message');
            self::assertSame(403, $importException->getCode(), "the upstream exception's code must be propagated");
            self::assertSame($upstream, $importException->getPrevious(), 'the API exception must be chained as previous');
        }
    }

    /**
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws LogicException
     */
    public function testImportIssueReportsTheIssueNumberWhenClosingFails(): void
    {
        $upstream = new GitHubApiException('not found', 404);
        $this->api->method('createIssue')->willReturn(new CreatedIssue(100));
        $this->api->method('closeIssue')->willThrowException($upstream);

        try {
            $this->service->importIssue(new Issue(title: 'X', state: IssueState::Closed));
            self::fail('importIssue() must surface the closing failure');
        } catch (ImportException $importException) {
            self::assertStringContainsString('Error closing issue #100', $importException->getMessage());
            self::assertSame(404, $importException->getCode(), "the upstream exception's code must be propagated");
            self::assertSame($upstream, $importException->getPrevious(), 'the API exception must be chained as previous');
        }
    }

    /**
     * @throws ImportException
     * @throws LogicException
     */
    public function testUploadAttachmentSendsTheFileToTheRepositoryAndAnswersItsUrl(): void
    {
        $this->api->method('getRepositoryId')->willReturn(1296269);

        $attachmentApi = $this->createMock(GitHubAttachmentApiClient::class);
        $attachmentApi->expects(self::once())->method('upload')
            ->with(1296269, 'shot.png', 'image/png', 'PNG-BYTES')
            ->willReturn('https://github.com/user-attachments/assets/bcf3a3ca-a300-45a1-b291-7d25174d12fe');

        $service = new GitHubService($this->api, attachmentApi: $attachmentApi);

        self::assertSame(
            'https://github.com/user-attachments/assets/bcf3a3ca-a300-45a1-b291-7d25174d12fe',
            $service->uploadAttachment('shot.png', 'image/png', 'PNG-BYTES')
        );
    }

    /**
     * @throws ImportException
     * @throws LogicException
     */
    public function testUploadAttachmentLooksUpTheRepositoryIdOnlyOnce(): void
    {
        $api = $this->apiMock();
        $api->expects(self::once())->method('getRepositoryId')->willReturn(1296269);

        $attachmentApi = self::createStub(GitHubAttachmentApiClient::class);
        $attachmentApi->method('upload')->willReturn('https://github.com/user-attachments/assets/uuid');

        $service = new GitHubService($api, attachmentApi: $attachmentApi);

        $service->uploadAttachment('first.png', 'image/png', 'BYTES');
        $service->uploadAttachment('second.png', 'image/png', 'BYTES');
    }

    /**
     * @throws ImportException
     * @throws LogicException
     */
    public function testUploadAttachmentWrapsApiErrorsSoAnImportCanCarryOn(): void
    {
        $upstream = new GitHubApiException('413 Payload Too Large', 413);

        $attachmentApi = self::createStub(GitHubAttachmentApiClient::class);
        $attachmentApi->method('upload')->willThrowException($upstream);

        $service = new GitHubService($this->api, attachmentApi: $attachmentApi);

        try {
            $service->uploadAttachment('huge.png', 'image/png', 'BYTES');
            self::fail('uploadAttachment() must surface the upload failure');
        } catch (ImportException $importException) {
            self::assertSame('413 Payload Too Large', $importException->getMessage());
            self::assertSame(413, $importException->getCode(), "the upstream exception's code must be propagated");
            self::assertSame($upstream, $importException->getPrevious(), 'the API exception must be chained as previous');
        }
    }

    /**
     * @throws ImportException
     * @throws LogicException
     */
    public function testUploadAttachmentReportsAFailedRepositoryLookup(): void
    {
        $this->api->method('getRepositoryId')->willThrowException(new GitHubApiException('Not Found', 404));

        $attachmentApi = $this->createMock(GitHubAttachmentApiClient::class);
        $attachmentApi->expects(self::never())->method('upload');

        $service = new GitHubService($this->api, attachmentApi: $attachmentApi);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Not Found');

        $service->uploadAttachment('shot.png', 'image/png', 'BYTES');
    }

    public function testMergeFailureMessagesStartsEmpty(): void
    {
        self::assertSame([], $this->service->mergeFailureMessages);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestThrowsWhenSourceBranchEmpty(): void
    {
        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: '',
            targetBranch: 'main',
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Source or target branch is empty');

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestThrowsWhenTargetBranchEmpty(): void
    {
        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: '',
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Source or target branch is empty');

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestAcceptsASourceBranchNamedZero(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $prCreateParams = null;
        $api->expects(self::once())
            ->method('createPullRequest')
            ->willReturnCallback(static function (array $params) use (&$prCreateParams): CreatedPullRequest {
                $prCreateParams = $params;

                return new CreatedPullRequest(7, new ShaRef('pr-head-sha'));
            });

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: '0',
            targetBranch: 'main',
            baseSha: 'base-divergence',
        );

        $this->service->importMergeRequest($mr);

        self::assertIsArray($prCreateParams);
        self::assertSame('Maxcastel:0', $prCreateParams['head']);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestAcceptsATargetBranchNamedZero(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $prCreateParams = null;
        $api->expects(self::once())
            ->method('createPullRequest')
            ->willReturnCallback(static function (array $params) use (&$prCreateParams): CreatedPullRequest {
                $prCreateParams = $params;

                return new CreatedPullRequest(7, new ShaRef('pr-head-sha'));
            });

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: '0',
            baseSha: 'base-divergence',
        );

        $this->service->importMergeRequest($mr);

        self::assertIsArray($prCreateParams);
        self::assertSame('0', $prCreateParams['base']);
    }

    /**
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestErrorIsWrappedWithMRContext(): void
    {
        $upstream = new GitHubApiException('GitHub is down', 503);
        $this->api->method('getUserName')->willReturn('Maxcastel');
        $this->api->method('branchExists')->willThrowException($upstream);

        $mr = new MergeRequest(gitLabIid: 99, sourceBranch: 'feat', targetBranch: 'main');

        try {
            $this->service->importMergeRequest($mr);
            self::fail('Expected exception was not thrown');
        } catch (ImportException $importException) {
            self::assertStringContainsString('MR !99', $importException->getMessage());
            self::assertStringContainsString('main', $importException->getMessage());
            self::assertStringContainsString('GitHub is down', $importException->getMessage());
            self::assertSame(503, $importException->getCode(), "the upstream exception's code must be propagated");
            self::assertSame($upstream, $importException->getPrevious(), 'the API exception must be chained as previous');
        }
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestThrowsWhenNeitherMergeCommitNorBaseShaAvailable(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $mr = new MergeRequest(
            gitLabIid: 42,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            mergeCommitSha: '',
            baseSha: '',
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No merge commit SHA and no diff_refs.base_sha');

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestThrowsClearMessageWhenSourceBranchCannotBeCreated(): void
    {
        $this->api->method('branchExists')->willReturn(false);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $mr = new MergeRequest(
            gitLabIid: 7,
            sourceBranch: 'feat',
            targetBranch: 'main',
            commitSha: '',
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Cannot create source branch 'feat'");

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestCreatesTheSourceBranchFromACommitShaNamedZero(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(false);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));
        $api->method('createPullRequest')->willReturn(new CreatedPullRequest(7, new ShaRef('pr-head-sha')));

        $api->expects(self::once())
            ->method('createBranch')
            ->with('feat', '0');

        $mr = new MergeRequest(
            gitLabIid: 7,
            sourceBranch: 'feat',
            targetBranch: 'main',
            commitSha: '0',
            baseSha: 'base-divergence',
        );

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestTreatsAMergeCommitShaNamedZeroAsMerged(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));
        $api->method('getBranch')->willReturn(new BranchInfo('main', new ShaRef('current-main-sha')));
        $api->method('createPullRequest')->willReturn(new CreatedPullRequest(7, new ShaRef('pr-head-sha')));
        $api->method('createCommit')->willReturn(new CreatedCommit('new-github-merge-sha'));

        $api->expects(self::exactly(2))
            ->method('showCommit')
            ->with('0')
            ->willReturn(new GitCommit(
                new ShaRef('gitlab-merge-tree-sha'),
                [new ShaRef('pre-merge-base'), new ShaRef('feature-tip')],
                new GitCommitAuthor('A', 'a@a'),
            ));

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: strtotime('2025-09-14T12:00:00Z'),
            mergeCommitSha: '0',
            baseSha: '',
        );

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportNonMergedMergeRequestUsesABaseShaNamedZero(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));
        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(7, new ShaRef('pr-head-sha')));

        $refUpdates = [];
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$refUpdates): void {
                $refUpdates[] = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $mr = new MergeRequest(
            gitLabIid: 42,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            mergeCommitSha: '',
            baseSha: '0',
        );

        $this->service->importMergeRequest($mr);

        self::assertSame([
            ['branch' => 'main', 'sha' => '0'],
            ['branch' => 'main', 'sha' => '0'],
        ], $refUpdates);
    }

    /**
     * @throws DateMalformedStringException
     * @throws ImportException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportNonMergedMergeRequestUsesMainAsBaseAfterRebasingFeatureBranch(): void
    {
        $this->api->method('branchExists')->willReturn(true);

        $identity = ['name' => 'A', 'email' => 'a@a', 'date' => '2025-09-08T10:00:00Z'];
        $this->api->method('compareCommits')->willReturn(new CommitComparison([
            new RepoCommit(
                'old-tip',
                new RepoCommitDetail('feat: ripple impl', new ShaRef('tree-sha'), $identity, $identity),
                null,
            ),
        ]));

        $refUpdates = [];
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$refUpdates): void {
                $refUpdates[] = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $createdCommitParams = null;
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createdCommitParams): CreatedCommit {
                $createdCommitParams = $params;

                return new CreatedCommit('rebased-tip-sha');
            }
        );

        $prCreateParams = null;
        $this->api->method('createPullRequest')
            ->willReturnCallback(static function (array $params) use (&$prCreateParams): CreatedPullRequest {
                $prCreateParams = $params;

                return new CreatedPullRequest(7, new ShaRef('pr-head-sha'));
            });

        $mr = new MergeRequest(
            gitLabIid: 42,
            gitLabUrl: 'https://gitlab.com/x/-/merge_requests/42',
            title: 'feat: add ripple',
            description: 'PR description',
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            createdAt: strtotime('2025-09-08T10:00:00Z'),
            baseSha: 'base-divergence',
        );

        $this->service->importMergeRequest($mr);

        self::assertIsArray($createdCommitParams);
        self::assertSame('feat: ripple impl', $createdCommitParams['message']);
        self::assertSame('tree-sha', $createdCommitParams['tree']);
        self::assertSame(['base-divergence'], $createdCommitParams['parents'], 'Rebased commit parent must be mainBase (the new base)');

        self::assertSame([
            ['branch' => 'feat', 'sha' => 'rebased-tip-sha'],
            ['branch' => 'main', 'sha' => 'base-divergence'],
            ['branch' => 'main', 'sha' => 'base-divergence'],
        ], $refUpdates);

        self::assertIsArray($prCreateParams);
        self::assertSame('main', $prCreateParams['base'], 'Non-merged MR must use main as base (rebased branch sits on top of main)');
        self::assertSame('feat: add ripple', $prCreateParams['title']);
        self::assertSame('Maxcastel:feat', $prCreateParams['head']);

        $tsUtc = strtotime('2025-09-08T10:00:00Z');
        $expectedDate = (new DateTimeImmutable('@'.$tsUtc))
            ->setTimezone(new DateTimeZone('Europe/Paris'))
            ->format('d/m/Y H:i:s');
        $expectedBody = "**Created on GitLab:** {$expectedDate}\n"
            ."**GitLab MR:** [!42](https://gitlab.com/x/-/merge_requests/42)\n\n"
            .'PR description';
        self::assertSame($expectedBody, $prCreateParams['body']);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testRebaseOmitsAuthorAndCommitterWhenGitHubReportsNoIdentity(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([
            new RepoCommit(
                'old-tip',
                new RepoCommitDetail('feat: no identity', new ShaRef('tree-sha'), null, null),
                null,
            ),
        ]));

        $createdCommitParams = null;
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createdCommitParams): CreatedCommit {
                $createdCommitParams = $params;

                return new CreatedCommit('rebased-tip-sha');
            }
        );
        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(7, new ShaRef('pr-head-sha')));

        $this->service->importMergeRequest(new MergeRequest(
            gitLabIid: 42,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'base-divergence',
        ));

        self::assertIsArray($createdCommitParams);
        self::assertSame('feat: no identity', $createdCommitParams['message']);
        self::assertArrayNotHasKey('author', $createdCommitParams, 'a null author must be omitted, not forwarded as null');
        self::assertArrayNotHasKey('committer', $createdCommitParams, 'a null committer must be omitted, not forwarded as null');
    }

    /**
     * @throws DateMalformedStringException
     * @throws ImportException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergedMergeRequestBuildsCustomMergeCommitWithGitLabAuthorDateAndAdvancesMain(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('getBranch')->willReturnCallback(
            static function (string $branch): BranchInfo {
                if ('main' === $branch) {
                    return new BranchInfo('main', new ShaRef('current-main-sha'));
                }

                return new BranchInfo($branch, new ShaRef('feat-sha'));
            }
        );
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $refUpdateCalls = [];
        $api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$refUpdateCalls): void {
                $refUpdateCalls[] = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('gitlab-merge-tree-sha'),
            [new ShaRef('pre-merge-base'), new ShaRef('feature-tip')],
            new GitCommitAuthor('Maxence Castel', 'maxence@gitlab.example'),
        ));

        $createParams = null;
        $api->expects(self::once())
            ->method('createCommit')
            ->willReturnCallback(static function (array $params) use (&$createParams): CreatedCommit {
                $createParams = $params;

                return new CreatedCommit('new-github-merge-sha');
            });

        $api->method('createPullRequest')->willReturn(new CreatedPullRequest(7, new ShaRef('pr-head-sha')));

        $api->expects(self::never())->method('closePullRequest');

        $mergedAt = strtotime('2025-09-14T12:00:00Z');
        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: $mergedAt,
            mergeCommitSha: 'gitlab-merge-sha',
        );

        $this->service->importMergeRequest($mr);

        self::assertIsArray($createParams, 'createCommit must be called for the merge');
        self::assertSame(
            'Merge pull request #7 from Maxcastel/feat',
            $createParams['message'],
            'Message must match GitHub auto-link format'
        );
        self::assertSame('gitlab-merge-tree-sha', $createParams['tree'],
            'Tree must come from the synced GitLab merge commit, not featureTip');
        self::assertSame(['current-main-sha', 'pr-head-sha'], $createParams['parents'],
            'parent[0] = current main, parent[1] = PR head — order matters for first-parent path');

        self::assertIsArray($createParams['author']);
        self::assertSame('Maxence Castel', $createParams['author']['name']);
        self::assertSame('maxence@gitlab.example', $createParams['author']['email']);
        $expectedIsoDate = (new DateTimeImmutable('@'.$mergedAt))->format(DateTimeInterface::ATOM);
        self::assertSame($expectedIsoDate, $createParams['author']['date'], 'Author date must be the GitLab merge date in ISO 8601');
        self::assertSame($createParams['author'], $createParams['committer'], 'Committer must equal author so display date matches GitLab');

        self::assertSame([
            ['branch' => 'feat', 'sha' => 'feature-tip'],
            ['branch' => 'main', 'sha' => 'pre-merge-base'],
            ['branch' => 'main', 'sha' => 'pre-merge-base'],
            ['branch' => 'main', 'sha' => 'new-github-merge-sha'],
        ], $refUpdateCalls);

        self::assertSame(
            'new-github-merge-sha',
            ServiceMockHelper::getPrivateProperty($this->service, 'lastMergeShaForMain')
        );
        self::assertSame([], $this->service->mergeFailureMessages);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergedMergeRequestFallsBackToAuthenticatedUserWhenGitLabAuthorMissing(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('getBranch')->willReturnCallback(
            static function (string $branch): BranchInfo {
                if ('main' === $branch) {
                    return new BranchInfo('main', new ShaRef('current-main-sha'));
                }

                return new BranchInfo($branch, new ShaRef('feat-sha'));
            }
        );
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('gitlab-merge-tree-sha'),
            [new ShaRef('pre-merge-base'), new ShaRef('feature-tip')],
            new GitCommitAuthor(null, null),
        ));

        $createParams = null;
        $this->api->method('createCommit')
            ->willReturnCallback(static function (array $params) use (&$createParams): CreatedCommit {
                $createParams = $params;

                return new CreatedCommit('new-github-merge-sha');
            });

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(7, new ShaRef('pr-head-sha')));

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: strtotime('2025-09-14T12:00:00Z'),
            mergeCommitSha: 'gitlab-merge-sha',
        );

        $this->service->importMergeRequest($mr);

        self::assertIsArray($createParams, 'createCommit must be called for the merge');
        self::assertIsArray($createParams['author']);
        self::assertSame('Maxcastel', $createParams['author']['name'], 'Missing GitLab author name must fall back to the authenticated userName');
        self::assertSame('Maxcastel@users.noreply.github.com', $createParams['author']['email'], 'Missing GitLab author email must fall back to <userName>@users.noreply.github.com');
    }

    public function testCreatePrWithPreMergeBaseRestoresMainViaFinallyWhenPrCreationThrows(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $mainUpdateCount = 0;
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$mainUpdateCount): void {
                if ('main' === $branch) {
                    ++$mainUpdateCount;
                }
            }
        );

        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('unused-tree'),
            [new ShaRef('pre-merge-base'), new ShaRef('feature-tip')],
            null,
        ));

        $this->api->method('createPullRequest')
            ->willThrowException(new GitHubApiException('upstream rate-limited'));

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergeCommitSha: 'gitlab-merge-sha',
        );

        $caught = null;
        try {
            $this->service->importMergeRequest($mr);
        } catch (Exception $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(Exception::class, $caught, 'Exception must propagate after finally runs');
        self::assertStringContainsString('upstream rate-limited', $caught->getMessage());

        self::assertSame(2, $mainUpdateCount, 'main must be updated TWICE: once before the try, once in finally. '
        .'If only 1 call: the finally was unwrapped and main is left in a manipulated state.');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testCreatePrWithPreMergeBaseSwallowsAFailedMainRestoreInFinally(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $mainUpdateCount = 0;
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$mainUpdateCount): void {
                if ('main' !== $branch) {
                    return;
                }

                if (2 === ++$mainUpdateCount) {
                    throw new GitHubApiException('main is protected, cannot restore');
                }
            }
        );

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(51, new ShaRef('pr-head-sha')));

        $mr = new MergeRequest(
            gitLabIid: 4,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'pre-merge-base',
        );

        $this->service->importMergeRequest($mr);

        self::assertSame(2, $mainUpdateCount, 'the finally block must still attempt the restore');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestRequestsReviewersViaPullRequestEndpoint(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $api->expects(self::once())
            ->method('requestReviewers')
            ->with(42, ['Maxcastel']);

        $api->method('createPullRequest')->willReturn(new CreatedPullRequest(42, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 99,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'b',
            reviewers: ['some-gitlab-reviewer', 'another-gitlab-user'],
        );

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestSwallowsReviewRequestExceptionWhenAuthorIsReviewer(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $api->expects(self::once())
            ->method('requestReviewers')
            ->willThrowException(
                new GitHubApiException('Review cannot be requested from pull request author.')
            );

        $api->expects(self::once())
            ->method('createPullRequest')
            ->willReturn(new CreatedPullRequest(1, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'b',
            reviewers: ['someone'],
        );

        $this->service->importMergeRequest($mr);

        self::assertSame([
            '!1' => 'Failed to request review from Maxcastel for PR #1 because the reviewer is also the author. Skipping review request.',
        ], $this->service->reviewRequestWarnings);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestSkipsReviewRequestWhenNoReviewers(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $api->method('createPullRequest')->willReturn(new CreatedPullRequest(1, new ShaRef('h')));
        $api->expects(self::never())->method('requestReviewers');

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'b',
        );

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportDraftMergeRequestCreatesPullRequestWithDraftTrue(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $capturedParams = null;
        $this->api->method('createPullRequest')->willReturnCallback(
            static function (array $params) use (&$capturedParams): CreatedPullRequest {
                $capturedParams = $params;

                return new CreatedPullRequest(1, new ShaRef('h'));
            }
        );

        $mr = new MergeRequest(
            gitLabIid: 4,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'b',
            isDraft: true,
        );

        $this->service->importMergeRequest($mr);

        self::assertIsArray($capturedParams);
        self::assertTrue($capturedParams['draft'] ?? false, 'A draft MR must be created as a draft PR on GitHub');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportNonDraftMergeRequestDoesNotSendDraftParam(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $capturedParams = null;
        $this->api->method('createPullRequest')->willReturnCallback(
            static function (array $params) use (&$capturedParams): CreatedPullRequest {
                $capturedParams = $params;

                return new CreatedPullRequest(1, new ShaRef('h'));
            }
        );

        $mr = new MergeRequest(
            gitLabIid: 5,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'b',
            isDraft: false,
        );

        $this->service->importMergeRequest($mr);

        self::assertIsArray($capturedParams);
        self::assertArrayNotHasKey('draft', $capturedParams, 'A non-draft MR must NOT pass a draft key');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestFallsBackToUniqueBranchWhenCreatePrRaisesAlreadyExists(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('getBranch')->willReturn(new BranchInfo('feat', new ShaRef('feat-sha')));
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $createBranchCall = null;
        $this->api->method('createBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$createBranchCall): void {
                $createBranchCall = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('t'),
            [new ShaRef('pre'), new ShaRef('tip')],
            new GitCommitAuthor('X', 'x@x'),
        ));
        $this->api->method('createCommit')->willReturn(new CreatedCommit('new-merge'));

        $createCallCount = 0;
        $fallbackCreateParams = null;
        $this->api->method('createPullRequest')->willReturnCallback(
            static function (array $params) use (&$createCallCount, &$fallbackCreateParams): CreatedPullRequest {
                ++$createCallCount;
                if (1 === $createCallCount) {
                    throw new GitHubApiException('A pull request already exists for Maxcastel:feat.');
                }

                $fallbackCreateParams = $params;

                return new CreatedPullRequest(99, new ShaRef('h'));
            }
        );

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergeCommitSha: 'gitlab-merge-sha',
        );

        $this->service->importMergeRequest($mr);

        self::assertSame(2, $createCallCount, 'create() must be called twice: original try then createPRWithUniqueBranch fallback');

        self::assertIsArray($createBranchCall, 'createBranch must have been called');
        self::assertSame('feat', $createBranchCall['branch']);
        self::assertSame('feat-sha', $createBranchCall['sha']);
        self::assertIsArray($fallbackCreateParams, 'the fallback createPullRequest must have been called');
        self::assertSame('Maxcastel:feat', $fallbackCreateParams['head']);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestRethrowsWhenCreatePrFailsForNonExistenceReason(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));
        $this->api->method('createPullRequest')
            ->willThrowException(new GitHubApiException('validation failed'));

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'b',
        );

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('validation failed');

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergedMergeRequestCherryPicksNonMrCommitsBetweenPreviousMergeAndThisOne(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'previous-gitlab-merge'
        );

        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('getBranch')->willReturn(new BranchInfo('main', new ShaRef('after-cherrypick')));

        $compareCalls = [];
        $this->api->method('compareCommits')->willReturnCallback(
            static function (string $base, string $head) use (&$compareCalls): CommitComparison {
                $compareCalls[] = ['base' => $base, 'head' => $head];

                if ('previous-gitlab-merge' === $base) {
                    $bot = ['name' => 'Release Bot', 'email' => 'bot@example.com', 'date' => '2025-09-09T00:00:00Z'];

                    return new CommitComparison([
                        new RepoCommit(
                            'chore-release-sha',
                            new RepoCommitDetail('chore(release): v0.10.0', new ShaRef('chore-tree'), $bot, $bot),
                            [new ShaRef('previous-gitlab-merge')],
                        ),
                        new RepoCommit(
                            'some-gitlab-merge',
                            new RepoCommitDetail('Merge branch ...', new ShaRef('t'), [], []),
                            [new ShaRef('p0'), new ShaRef('p1')],
                        ),
                    ]);
                }

                return new CommitComparison([]);
            }
        );

        $refUpdates = [];
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$refUpdates): void {
                $refUpdates[] = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $createdCommits = [];
        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('tree-2'),
            [new ShaRef('baseBeforeMerge-2'), new ShaRef('feature-tip-2')],
            new GitCommitAuthor('A', 'a@a'),
        ));
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createdCommits): CreatedCommit {
                $createdCommits[] = $params;

                return new CreatedCommit('created-'.\count($createdCommits));
            }
        );

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(2, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 2,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: strtotime('2025-09-09T12:00:00Z'),
            mergeCommitSha: 'gitlab-merge-2',
        );

        $this->service->importMergeRequest($mr);

        self::assertNotEmpty($compareCalls);
        self::assertSame('previous-gitlab-merge', $compareCalls[0]['base']);
        self::assertSame('baseBeforeMerge-2', $compareCalls[0]['head']);

        $cherryPickCreate = $createdCommits[0] ?? null;
        self::assertIsArray($cherryPickCreate);
        self::assertSame('chore(release): v0.10.0', $cherryPickCreate['message']);
        self::assertSame('chore-tree', $cherryPickCreate['tree']);
        self::assertSame(['previous-github-merge'], $cherryPickCreate['parents'], 'Cherry-picked chore commit must have the previous GitHub merge as parent');
        self::assertIsArray($cherryPickCreate['author']);
        self::assertSame('Release Bot', $cherryPickCreate['author']['name']);
        self::assertSame('2025-09-09T00:00:00Z', $cherryPickCreate['author']['date'], 'Author date must be preserved from GitLab');

        self::assertCount(2, $createdCommits, 'continue; must skip GitLab merge commits — only chore + Merge pull request must be created');

        $mainUpdates = array_values(array_filter(
            $refUpdates, static fn (array $c): bool => 'main' === $c['branch']
        ));
        self::assertSame([
            'created-1',
            'created-1',
            'created-1',
            'created-2',
        ], array_column($mainUpdates, 'sha'));

        self::assertSame('gitlab-merge-2', ServiceMockHelper::getPrivateProperty($this->service, 'lastReplayedGitLabMainSha'));
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testCreatePrWithPreMergeBaseDoesNotAdvanceMainBaseWhenInBetweenCherryPickFindsOnlyMerges(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'previous-gitlab-merge'
        );

        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('getBranch')->willReturn(new BranchInfo('main', new ShaRef('main-sha')));

        $this->api->method('compareCommits')->willReturnCallback(
            static function (string $base): CommitComparison {
                if ('previous-gitlab-merge' === $base) {
                    return new CommitComparison([
                        new RepoCommit(
                            'some-gitlab-merge',
                            new RepoCommitDetail('Merge branch ...', new ShaRef('t'), [], []),
                            [new ShaRef('p0'), new ShaRef('p1')],
                        ),
                    ]);
                }

                return new CommitComparison([]);
            }
        );

        $refUpdates = [];
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$refUpdates): void {
                $refUpdates[] = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('tree-2'),
            [new ShaRef('baseBeforeMerge-2'), new ShaRef('feature-tip-2')],
            new GitCommitAuthor('A', 'a@a'),
        ));
        $this->api->method('createCommit')->willReturn(new CreatedCommit('new-merge'));
        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(2, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 2,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: strtotime('2025-09-09T12:00:00Z'),
            mergeCommitSha: 'gitlab-merge-2',
        );

        $this->service->importMergeRequest($mr);

        $mainUpdates = array_values(array_filter($refUpdates, static fn (array $c): bool => 'main' === $c['branch']));
        self::assertSame('previous-github-merge', $mainUpdates[0]['sha'] ?? null, 'mainBase must remain the previous lastMergeShaForMain when the in-between cherry-pick found nothing to carry over');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportFirstMergedMergeRequestDoesNotAttemptCherryPick(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('getBranch')->willReturn(new BranchInfo('main', new ShaRef('init')));

        $compareCalls = [];
        $this->api->method('compareCommits')->willReturnCallback(
            static function (string $base, string $head) use (&$compareCalls): CommitComparison {
                $compareCalls[] = ['base' => $base, 'head' => $head];

                return new CommitComparison([]);
            }
        );
        $refUpdates = [];
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$refUpdates): void {
                $refUpdates[] = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('t'),
            [new ShaRef('baseBeforeMerge'), new ShaRef('feat-tip')],
            new GitCommitAuthor('A', 'a@a'),
        ));
        $this->api->method('createCommit')->willReturn(new CreatedCommit('new-merge'));

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(1, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: strtotime('2025-09-09T12:00:00Z'),
            mergeCommitSha: 'gitlab-merge',
        );

        $this->service->importMergeRequest($mr);

        foreach ($compareCalls as $call) {
            self::assertSame('baseBeforeMerge', $call['base'], 'No compare call must reference a previous GitLab merge — this is the first MR');
        }

        $featBranchUpdates = array_filter($refUpdates, static fn (array $c): bool => 'feat' === $c['branch']);
        self::assertCount(1, $featBranchUpdates, 'feat must be force-updated exactly once (the featureTip reset) — rebaseFeatureBranchOnto must '
        .'skip its own forceUpdateBranch when the rebase compare found nothing to replay');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testReplaysABranchThatNeverCaughtUpWithMainOnItsOwnDivergencePoint(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'previous-gitlab-merge'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge'
        );

        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('getBranch')->willReturn(new BranchInfo('main', new ShaRef('main-tip')));
        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('gitlab-merge-tree'),
            [new ShaRef('main-with-release'), new ShaRef('feature-tip')],
            new GitCommitAuthor('A', 'a@a'),
        ));

        $identity = ['name' => 'A', 'email' => 'a@a', 'date' => '2025-07-21T10:00:00Z'];
        $this->api->method('compareCommits')->willReturnCallback(
            static function (string $base, string $head) use ($identity): CommitComparison {
                if ('main-with-release' === $head) {
                    return new CommitComparison([
                        new RepoCommit(
                            'main-with-release',
                            new RepoCommitDetail('chore(release): v0.1.0', new ShaRef('release-tree'), $identity, $identity),
                            [new ShaRef('before-release')],
                        ),
                    ], new ShaRef('previous-gitlab-merge'));
                }

                return new CommitComparison([
                    new RepoCommit(
                        'feature-tip',
                        new RepoCommitDetail('feat(theme): add dark/light mode', new ShaRef('theme-tree'), $identity, $identity),
                        null,
                    ),
                ], new ShaRef('before-release'));
            }
        );

        $createdCommits = [];
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createdCommits): CreatedCommit {
                $createdCommits[] = $params;

                return new CreatedCommit('created-'.\count($createdCommits));
            }
        );

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(13, new ShaRef('pr-head')));

        $this->service->importMergeRequest(new MergeRequest(
            gitLabIid: 13,
            sourceBranch: 'add-dark-light-mode',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: strtotime('2025-07-21T12:00:00Z'),
            mergeCommitSha: 'gitlab-merge',
        ));

        self::assertSame('chore(release): v0.1.0', $createdCommits[0]['message']);
        self::assertSame(['previous-github-merge'], $createdCommits[0]['parents']);

        self::assertSame('feat(theme): add dark/light mode', $createdCommits[1]['message']);
        self::assertSame(
            ['before-release'],
            $createdCommits[1]['parents'],
            'The branch left main before the release commit, so it must be replayed there — stacking it on the '
            .'rebuilt tip would show the release commit as reverted in the PR diff'
        );
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testReplaysABranchOnTheRebuiltCommitWhenItsDivergencePointWasItselfRebuilt(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'previous-gitlab-merge'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge'
        );

        $this->api->method('branchExists')->willReturn(true);

        $identity = ['name' => 'A', 'email' => 'a@a', 'date' => '2025-09-14T10:00:00Z'];
        $this->api->method('compareCommits')->willReturnCallback(
            static function (string $base, string $head) use ($identity): CommitComparison {
                if ('gitlab-release' === $head) {
                    return new CommitComparison([
                        new RepoCommit(
                            'gitlab-release',
                            new RepoCommitDetail('chore(release): v0.11.0', new ShaRef('release-tree'), $identity, $identity),
                            [new ShaRef('previous-gitlab-merge')],
                        ),
                    ], new ShaRef('previous-gitlab-merge'));
                }

                return new CommitComparison([
                    new RepoCommit(
                        'branch-tip',
                        new RepoCommitDetail('ci: add tests', new ShaRef('ci-tree'), $identity, $identity),
                        null,
                    ),
                ], new ShaRef('gitlab-release'));
            }
        );

        $createdCommits = [];
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createdCommits): CreatedCommit {
                $createdCommits[] = $params;

                return new CreatedCommit('created-'.\count($createdCommits));
            }
        );

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(30, new ShaRef('pr-head')));

        $this->service->importMergeRequest(new MergeRequest(
            gitLabIid: 30,
            sourceBranch: 'ci-add-tests',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'gitlab-release',
        ));

        self::assertSame('chore(release): v0.11.0', $createdCommits[0]['message']);
        self::assertSame('ci: add tests', $createdCommits[1]['message']);
        self::assertSame(
            ['created-1'],
            $createdCommits[1]['parents'],
            'The divergence point was replayed during this import, so the branch must sit on the rebuilt SHA, not the GitLab one'
        );
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testCherryPicksNonMrMainCommitsBeforeRebasingAnOpenMergeRequest(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'previous-gitlab-merge'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge'
        );

        $this->api->method('branchExists')->willReturn(true);

        $identity = ['name' => 'A', 'email' => 'a@a', 'date' => '2025-09-14T10:00:00Z'];
        $this->api->method('compareCommits')->willReturnCallback(
            static function (string $base, string $head) use ($identity): CommitComparison {
                if ('previous-gitlab-merge' === $base && 'gitlab-release' === $head) {
                    return new CommitComparison([
                        new RepoCommit(
                            'gitlab-release',
                            new RepoCommitDetail('chore(release): v0.11.0', new ShaRef('release-tree'), $identity, $identity),
                            [new ShaRef('previous-gitlab-merge')],
                        ),
                    ]);
                }

                return new CommitComparison([
                    new RepoCommit(
                        'branch-first-commit',
                        new RepoCommitDetail('chore: add and setup ESLint', new ShaRef('eslint-tree'), $identity, $identity),
                        null,
                    ),
                ]);
            }
        );

        $refUpdates = [];
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$refUpdates): void {
                $refUpdates[] = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $createdCommits = [];
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createdCommits): CreatedCommit {
                $createdCommits[] = $params;

                return new CreatedCommit('created-'.\count($createdCommits));
            }
        );

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(30, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 16,
            title: 'ci: add tests',
            sourceBranch: 'ci-add-tests',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'gitlab-release',
        );

        $this->service->importMergeRequest($mr);

        self::assertCount(2, $createdCommits);
        self::assertSame('chore(release): v0.11.0', $createdCommits[0]['message']);
        self::assertSame(['previous-github-merge'], $createdCommits[0]['parents'], 'The main-only commit must be replayed on top of the rebuilt main');

        self::assertSame('chore: add and setup ESLint', $createdCommits[1]['message']);
        self::assertSame(['created-1'], $createdCommits[1]['parents'], "The MR's first commit must sit on the replayed main-only commit, otherwise its changes leak into the PR diff");

        self::assertSame([
            ['branch' => 'main', 'sha' => 'created-1'],
            ['branch' => 'ci-add-tests', 'sha' => 'created-2'],
            ['branch' => 'main', 'sha' => 'created-1'],
            ['branch' => 'main', 'sha' => 'created-1'],
        ], $refUpdates);

        self::assertSame('gitlab-release', ServiceMockHelper::getPrivateProperty($this->service, 'lastReplayedGitLabMainSha'), 'The replay cursor must move to the MR base so those commits are not replayed twice');
        self::assertSame('created-1', ServiceMockHelper::getPrivateProperty($this->service, 'lastMergeShaForMain'));
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testSeveralOpenMergeRequestsSharingTheSameBaseAllSitOnTheSameReplayedMain(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'previous-gitlab-merge'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge'
        );

        $this->api->method('branchExists')->willReturn(true);

        $identity = ['name' => 'A', 'email' => 'a@a', 'date' => '2025-09-18T10:00:00Z'];
        $cherryPickCompares = 0;
        $this->api->method('compareCommits')->willReturnCallback(
            static function (string $base, string $head) use ($identity, &$cherryPickCompares): CommitComparison {
                if ('gitlab-release' === $head) {
                    ++$cherryPickCompares;

                    return new CommitComparison([
                        new RepoCommit(
                            'gitlab-release',
                            new RepoCommitDetail('chore(release): v0.11.0', new ShaRef('release-tree'), $identity, $identity),
                            [new ShaRef('previous-gitlab-merge')],
                        ),
                    ]);
                }

                return new CommitComparison([
                    new RepoCommit(
                        $head.'-tip',
                        new RepoCommitDetail('commit of '.$head, new ShaRef($head.'-tree'), $identity, $identity),
                        null,
                    ),
                ]);
            }
        );

        $mainUpdates = [];
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$mainUpdates): void {
                if ('main' === $branch) {
                    $mainUpdates[] = $sha;
                }
            }
        );

        $createdCommits = [];
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createdCommits): CreatedCommit {
                $createdCommits[] = $params;

                return new CreatedCommit('created-'.\count($createdCommits));
            }
        );

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(27, new ShaRef('h')));

        foreach (['add-tab-ripple', 'account-screen-as-tab', 'fix-upgrade-expo-sdk'] as $index => $branch) {
            $this->service->importMergeRequest(new MergeRequest(
                gitLabIid: 27 + $index,
                sourceBranch: $branch,
                targetBranch: 'main',
                state: MergeRequestState::Opened,
                baseSha: 'gitlab-release',
            ));
        }

        self::assertSame(1, $cherryPickCompares, 'The main-only release commit must be replayed once, not once per open MR');

        self::assertCount(4, $createdCommits, 'One replayed release commit + one commit per open MR');
        self::assertSame('chore(release): v0.11.0', $createdCommits[0]['message']);
        self::assertSame(['previous-github-merge'], $createdCommits[0]['parents']);

        self::assertSame('commit of add-tab-ripple', $createdCommits[1]['message']);
        self::assertSame(['created-1'], $createdCommits[1]['parents']);
        self::assertSame('commit of account-screen-as-tab', $createdCommits[2]['message']);
        self::assertSame(['created-1'], $createdCommits[2]['parents'], 'Every open MR must branch off the replayed main, not off the one before it');
        self::assertSame('commit of fix-upgrade-expo-sdk', $createdCommits[3]['message']);
        self::assertSame(['created-1'], $createdCommits[3]['parents']);

        self::assertSame(['created-1', 'created-1', 'created-1', 'created-1', 'created-1', 'created-1', 'created-1'], $mainUpdates, 'main must stay on the replayed release commit for the whole run');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testNoCherryPickWhenTheMergeRequestBaseIsAlreadyTheLastReplayedCommit(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'gitlab-base'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge'
        );

        $this->api->method('branchExists')->willReturn(true);

        $compareCalls = [];
        $this->api->method('compareCommits')->willReturnCallback(
            static function (string $base, string $head) use (&$compareCalls): CommitComparison {
                $compareCalls[] = ['base' => $base, 'head' => $head];

                return new CommitComparison([]);
            }
        );

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(1, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 16,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'gitlab-base',
        );

        $this->service->importMergeRequest($mr);

        self::assertSame([
            ['base' => 'gitlab-base', 'head' => 'feat'],
        ], $compareCalls, 'Only the rebase compare must run: main is already replayed up to the MR base');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testFirstNonMergedMergeRequestRecordsWhereGitLabMainWasReplayed(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));
        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(1, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 16,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'gitlab-base',
        );

        $this->service->importMergeRequest($mr);

        self::assertSame('gitlab-base', ServiceMockHelper::getPrivateProperty($this->service, 'lastMergeShaForMain'), 'main on GitHub now points at the MR base');
        self::assertSame('gitlab-base', ServiceMockHelper::getPrivateProperty($this->service, 'lastReplayedGitLabMainSha'), 'Without it, trailing GitLab main commits would never be replayed when no MR is merged');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws GitHubApiException
     */
    public function testCherryPickReturnsEmptyAndDoesNotTouchMainWhenAllInBetweenCommitsAreMerges(): void
    {
        $api = $this->apiMock();

        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'previous-gitlab-merge'
        );

        $compareCalls = 0;
        $api->method('compareCommits')->willReturnCallback(
            static function () use (&$compareCalls): CommitComparison {
                ++$compareCalls;

                return new CommitComparison([
                    new RepoCommit(
                        'gitlab-merge-in-between',
                        new RepoCommitDetail('Merge branch x', new ShaRef('t'), [], []),
                        [new ShaRef('p0'), new ShaRef('p1')],
                    ),
                ]);
            }
        );

        $api->expects(self::never())->method('createCommit');
        $api->expects(self::never())->method('forceUpdateBranch');

        $this->service->cherryPickTrailingNonMrCommits('gitlab-main-head', 'main');

        self::assertSame(1, $compareCalls);

        self::assertSame('previous-github-merge', ServiceMockHelper::getPrivateProperty($this->service, 'lastMergeShaForMain'), 'lastMergeShaForMain must stay unchanged when no actual cherry-pick happens');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportSecondMergedMergeRequestUsesPreviousGithubMergeShaAsBase(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge-sha'
        );

        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('getBranch')->willReturnCallback(
            static function (string $branch): BranchInfo {
                if ('main' === $branch) {
                    return new BranchInfo('main', new ShaRef('previous-github-merge-sha'));
                }

                return new BranchInfo($branch, new ShaRef('feat-sha'));
            }
        );
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $refUpdateCalls = [];
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$refUpdateCalls): void {
                $refUpdateCalls[] = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('tree-2'),
            [
                new ShaRef('gitlab-pre-merge'),
                new ShaRef('feature-tip-2'),
            ],
            new GitCommitAuthor('Author', 'a@a'),
        ));
        $createParams = null;
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createParams): CreatedCommit {
                $createParams = $params;

                return new CreatedCommit('new-merge-2');
            }
        );

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(2, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 2,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: strtotime('2025-09-14T00:00:00Z'),
            mergeCommitSha: 'gitlab-merge-2',
        );

        $this->service->importMergeRequest($mr);

        $mainUpdatesViaPreMerge = array_values(array_filter(
            $refUpdateCalls,
            static fn (array $c): bool => 'main' === $c['branch'] && 'previous-github-merge-sha' === $c['sha']
        ));
        self::assertNotEmpty($mainUpdatesViaPreMerge, 'main must be positioned on the previous GitHub merge, not the GitLab pre-merge SHA');

        self::assertIsArray($createParams);
        self::assertIsArray($createParams['parents']);
        self::assertSame('previous-github-merge-sha', $createParams['parents'][0], 'parent[0] of the new merge MUST be the previous GitHub merge SHA — this is the chain link');
        self::assertSame('h', $createParams['parents'][1], 'parent[1] = PR head SHA');

        self::assertSame('new-merge-2', ServiceMockHelper::getPrivateProperty($this->service, 'lastMergeShaForMain'));
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergedMergeRequestRecordsFailureAndClosesPrWhenCommitCreateThrows(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('getBranch')->willReturn(new BranchInfo('main', new ShaRef('main-sha')));
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('t'),
            [new ShaRef('a'), new ShaRef('b')],
            new GitCommitAuthor('X', 'x@x'),
        ));
        $api->method('createCommit')
            ->willThrowException(new GitHubApiException('Tree object does not exist'));

        $api->method('createPullRequest')->willReturn(new CreatedPullRequest(9, new ShaRef('h')));
        $api->expects(self::once())
            ->method('closePullRequest')
            ->with(9);

        $mr = new MergeRequest(
            gitLabIid: 42,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: strtotime('2025-09-14T00:00:00Z'),
            mergeCommitSha: 'gitlab-merge',
        );

        $this->service->importMergeRequest($mr);

        self::assertSame(['!42' => 'Tree object does not exist'], $this->service->mergeFailureMessages, 'Failure message must be keyed by "!iid"');
        self::assertSame('', ServiceMockHelper::getPrivateProperty($this->service, 'lastMergeShaForMain'), 'lastMergeShaForMain must NOT advance on failure');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportClosedMergeRequestCallsPullRequestUpdateWithClosedState(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $api->method('createPullRequest')->willReturn(new CreatedPullRequest(99, new ShaRef('s')));
        $api->expects(self::once())
            ->method('closePullRequest')
            ->with(99);

        $mr = new MergeRequest(
            gitLabIid: 5,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Closed,
            baseSha: 'b',
        );

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestCreatesSourceBranchFromCommitShaWhenAbsent(): void
    {
        $this->api->method('branchExists')->willReturn(false);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $createBranchCall = null;
        $callCount = 0;
        $this->api->method('createBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$callCount, &$createBranchCall): void {
                ++$callCount;
                if (1 === $callCount) {
                    $createBranchCall = ['branch' => $branch, 'sha' => $sha];
                }
            }
        );

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(1, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 99,
            sourceBranch: 'feat',
            targetBranch: 'main',
            commitSha: 'mr-head-commit-sha',
            baseSha: 'base',
        );

        $this->service->importMergeRequest($mr);

        self::assertIsArray($createBranchCall, 'STEP 1 must call createBranch with commitSha');
        self::assertSame('feat', $createBranchCall['branch']);
        self::assertSame('mr-head-commit-sha', $createBranchCall['sha']);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestAssignsHardcodedMaxcastelWhenMrHasAssignees(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $api->method('createPullRequest')->willReturn(new CreatedPullRequest(55, new ShaRef('h')));

        $api->expects(self::once())
            ->method('updateIssueAssignees')
            ->with(55, ['Maxcastel']);

        $mr = new MergeRequest(
            gitLabIid: 7,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'b',
            assignees: ['someone-on-gitlab'],
        );

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergeRequestSkipsAssigneesWhenNone(): void
    {
        $api = $this->apiMock();

        $api->method('branchExists')->willReturn(true);
        $api->method('compareCommits')->willReturn(new CommitComparison([]));

        $api->method('createPullRequest')->willReturn(new CreatedPullRequest(1, new ShaRef('h')));
        $api->expects(self::never())->method('updateIssueAssignees');

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Opened,
            baseSha: 'b',
        );

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testImportMergedMergeRequestThrowsWhenMergeCommitHasNoParents(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $this->api->method('showCommit')->willReturn(new GitCommit(new ShaRef('unused-tree'), [], null));

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergeCommitSha: 'gitlab-merge',
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Merge commit has no parents');

        $this->service->importMergeRequest($mr);
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testCreatePrWithPreMergeBaseLeavesFeatureTipNullWithOnlyOneParent(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('getBranch')->willReturn(new BranchInfo('main', new ShaRef('main-sha')));
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $sourceBranchTouched = false;
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch) use (&$sourceBranchTouched): void {
                if ('feat' === $branch) {
                    $sourceBranchTouched = true;
                }
            }
        );
        $this->api->method('createBranch')->willReturnCallback(
            static function (string $branch) use (&$sourceBranchTouched): void {
                if ('feat' === $branch) {
                    $sourceBranchTouched = true;
                }
            }
        );

        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('t'),
            [new ShaRef('only-parent')],
            new GitCommitAuthor('A', 'a@a'),
        ));
        $this->api->method('createCommit')->willReturn(new CreatedCommit('new-merge'));
        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(1, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergedAt: strtotime('2025-09-09T12:00:00Z'),
            mergeCommitSha: 'gitlab-merge',
        );

        $this->service->importMergeRequest($mr);

        self::assertFalse($sourceBranchTouched, 'with only 1 parent, featureTip must stay null — the source branch reset step must be skipped');
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function testCreatePrWithPreMergeBaseFallsBackToCreateWhenSourceRefUpdateFails(): void
    {
        $this->api->method('branchExists')->willReturn(true);
        $this->api->method('getBranch')->willReturn(new BranchInfo('main', new ShaRef('main-sha')));
        $this->api->method('compareCommits')->willReturn(new CommitComparison([]));

        $sourceCreateCall = null;
        $updateCallCount = 0;
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$updateCallCount): void {
                ++$updateCallCount;
                if (1 === $updateCallCount) {
                    throw new GitHubApiException('Reference does not exist');
                }
            }
        );
        $this->api->method('createBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$sourceCreateCall): void {
                if (null === $sourceCreateCall) {
                    $sourceCreateCall = ['branch' => $branch, 'sha' => $sha];
                }
            }
        );

        $this->api->method('showCommit')->willReturn(new GitCommit(
            new ShaRef('t'),
            [new ShaRef('base'), new ShaRef('feature-tip-sha')],
            new GitCommitAuthor('X', 'x@x'),
        ));
        $this->api->method('createCommit')->willReturn(new CreatedCommit('new-merge'));

        $this->api->method('createPullRequest')->willReturn(new CreatedPullRequest(1, new ShaRef('h')));

        $mr = new MergeRequest(
            gitLabIid: 1,
            sourceBranch: 'feat',
            targetBranch: 'main',
            state: MergeRequestState::Merged,
            mergeCommitSha: 'gitlab-merge',
        );

        $this->service->importMergeRequest($mr);

        self::assertIsArray($sourceCreateCall, 'createBranch() must be called as fallback when forceUpdateBranch() throws');
        self::assertSame('feat', $sourceCreateCall['branch']);
        self::assertSame('feature-tip-sha', $sourceCreateCall['sha']);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws GitHubApiException
     */
    public function testCherryPickTrailingNonMrCommitsAppliesFinalNonMergeCommitsOnMain(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'last-github-merge'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'last-gitlab-merge'
        );

        $compareCalls = [];
        $this->api->method('compareCommits')->willReturnCallback(
            static function (string $base, string $head) use (&$compareCalls): CommitComparison {
                $compareCalls[] = ['base' => $base, 'head' => $head];

                $bot = ['name' => 'Bot', 'email' => 'bot@x', 'date' => '2025-09-14T12:00:00Z'];

                return new CommitComparison([
                    new RepoCommit(
                        'final-chore-sha',
                        new RepoCommitDetail('chore(release): v0.11.0', new ShaRef('final-tree'), $bot, $bot),
                        [new ShaRef('last-gitlab-merge')],
                    ),
                ]);
            }
        );

        $createdCommit = null;
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createdCommit): CreatedCommit {
                $createdCommit = $params;

                return new CreatedCommit('new-chore-sha');
            }
        );

        $refUpdates = [];
        $this->api->method('forceUpdateBranch')->willReturnCallback(
            static function (string $branch, string $sha) use (&$refUpdates): void {
                $refUpdates[] = ['branch' => $branch, 'sha' => $sha];
            }
        );

        $this->service->cherryPickTrailingNonMrCommits('gitlab-main-head', 'main');

        self::assertSame('last-gitlab-merge', $compareCalls[0]['base']);
        self::assertSame('gitlab-main-head', $compareCalls[0]['head']);

        self::assertIsArray($createdCommit);
        self::assertSame('chore(release): v0.11.0', $createdCommit['message']);
        self::assertSame('final-tree', $createdCommit['tree']);
        self::assertSame(['last-github-merge'], $createdCommit['parents']);
        self::assertIsArray($createdCommit['author']);
        self::assertSame('2025-09-14T12:00:00Z', $createdCommit['author']['date']);

        self::assertSame([
            ['branch' => 'main', 'sha' => 'new-chore-sha'],
        ], $refUpdates);

        self::assertSame('new-chore-sha', ServiceMockHelper::getPrivateProperty($this->service, 'lastMergeShaForMain'));
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws GitHubApiException
     */
    public function testCherryPickContinuesPastMergeCommitsInsteadOfBreakingOut(): void
    {
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastMergeShaForMain', 'previous-github-merge'
        );
        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'previous-gitlab-merge'
        );

        $x = ['name' => 'X', 'email' => 'x@x', 'date' => '2025-01-01T00:00:00Z'];
        $y = ['name' => 'Y', 'email' => 'y@y', 'date' => '2025-01-02T00:00:00Z'];
        $this->api->method('compareCommits')->willReturn(new CommitComparison([
            new RepoCommit(
                'chore-A',
                new RepoCommitDetail('chore A', new ShaRef('tree-A'), $x, $x),
                [new ShaRef('previous-gitlab-merge')],
            ),
            new RepoCommit(
                'gitlab-merge-in-between',
                new RepoCommitDetail('Merge branch x', new ShaRef('m-tree'), [], []),
                [new ShaRef('p0'), new ShaRef('p1')],
            ),
            new RepoCommit(
                'chore-C',
                new RepoCommitDetail('chore C', new ShaRef('tree-C'), $y, $y),
                [new ShaRef('gitlab-merge-in-between')],
            ),
        ]));

        $createdCommits = [];
        $this->api->method('createCommit')->willReturnCallback(
            static function (array $params) use (&$createdCommits): CreatedCommit {
                $createdCommits[] = $params;

                return new CreatedCommit('cp-'.\count($createdCommits));
            }
        );

        $this->service->cherryPickTrailingNonMrCommits('gitlab-main-head', 'main');

        self::assertCount(2, $createdCommits, 'continue; must skip the merge AND keep iterating; break; would stop after A');
        self::assertSame('chore A', $createdCommits[0]['message']);
        self::assertSame('chore C', $createdCommits[1]['message']);

        self::assertSame(['cp-1'], $createdCommits[1]['parents'], 'chore C must be created on top of A (cp-1) — proves continue, not break');
    }

    /**
     * @throws GitHubApiException
     */
    public function testCherryPickTrailingNonMrCommitsIsNoOpWhenNoMergeWasImported(): void
    {
        $api = $this->apiMock();

        $api->expects(self::never())->method('compareCommits');
        $api->expects(self::never())->method('createCommit');
        $api->expects(self::never())->method('forceUpdateBranch');

        $this->service->cherryPickTrailingNonMrCommits('anything', 'main');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws GitHubApiException
     */
    public function testCherryPickTrailingNonMrCommitsIsNoOpWhenGitLabHeadEqualsLastImportedMerge(): void
    {
        $api = $this->apiMock();

        ServiceMockHelper::setPrivateProperty(
            $this->service, 'lastReplayedGitLabMainSha', 'same-sha'
        );

        $api->expects(self::never())->method('compareCommits');
        $api->expects(self::never())->method('createCommit');
        $api->expects(self::never())->method('forceUpdateBranch');

        $this->service->cherryPickTrailingNonMrCommits('same-sha', 'main');
    }

    /**
     * @throws GitHubApiException
     */
    public function testProtectBranchWithDefaultsSendsForcePushAndDeletionBlockingBody(): void
    {
        $capturedBranch = null;
        $capturedBody = null;
        $this->api->method('updateBranchProtection')->willReturnCallback(
            static function (string $branch, array $params) use (&$capturedBranch, &$capturedBody): void {
                $capturedBranch = $branch;
                $capturedBody = $params;
            }
        );

        $this->service->protectBranchWithDefaults('main');

        self::assertSame('main', $capturedBranch);
        self::assertSame([
            'required_status_checks' => null,
            'enforce_admins' => false,
            'required_pull_request_reviews' => null,
            'restrictions' => null,
            'allow_force_pushes' => false,
            'allow_deletions' => false,
        ], $capturedBody);
    }

    /**
     * @throws GitHubApiException
     */
    public function testRestoreBranchProtectionTranslatesGetShapeIntoPutBody(): void
    {
        $saved = new BranchProtection(
            requiredStatusChecks: new RequiredStatusChecks(strict: true, contexts: ['ci/build']),
            requiredPullRequestReviews: new RequiredPullRequestReviews(
                dismissStaleReviews: true,
                requireCodeOwnerReviews: true,
                requiredApprovingReviewCount: 2,
                requireLastPushApproval: true,
                dismissalRestrictions: new DismissalRestrictions(
                    users: [new LoginRef('alice'), new LoginRef('bob')],
                    teams: [new SlugRef('core')],
                    apps: [new SlugRef('dismiss-app')],
                ),
            ),
            restrictions: new PushRestrictions(
                users: [new LoginRef('carol')],
                teams: [new SlugRef('admins')],
                apps: [new SlugRef('ci-app')],
            ),
            enforceAdmins: new ProtectionToggle(true),
            requiredLinearHistory: new ProtectionToggle(true),
            allowForcePushes: new ProtectionToggle(true),
            allowDeletions: new ProtectionToggle(true),
            blockCreations: new ProtectionToggle(true),
            requiredConversationResolution: new ProtectionToggle(true),
            lockBranch: new ProtectionToggle(true),
            allowForkSyncing: new ProtectionToggle(true),
        );

        $capturedBranch = null;
        $capturedBody = null;
        $this->api->method('updateBranchProtection')->willReturnCallback(
            static function (string $branch, array $params) use (&$capturedBranch, &$capturedBody): void {
                $capturedBranch = $branch;
                $capturedBody = $params;
            }
        );

        $this->service->restoreBranchProtection('main', $saved);

        self::assertSame('main', $capturedBranch);
        self::assertSame([
            'required_status_checks' => ['strict' => true, 'contexts' => ['ci/build']],
            'enforce_admins' => true,
            'required_pull_request_reviews' => [
                'dismiss_stale_reviews' => true,
                'require_code_owner_reviews' => true,
                'required_approving_review_count' => 2,
                'require_last_push_approval' => true,
                'dismissal_restrictions' => [
                    'users' => ['alice', 'bob'],
                    'teams' => ['core'],
                    'apps' => ['dismiss-app'],
                ],
            ],
            'restrictions' => [
                'users' => ['carol'],
                'teams' => ['admins'],
                'apps' => ['ci-app'],
            ],
            'required_linear_history' => true,
            'allow_force_pushes' => true,
            'allow_deletions' => true,
            'block_creations' => true,
            'required_conversation_resolution' => true,
            'lock_branch' => true,
            'allow_fork_syncing' => true,
        ], $capturedBody);
    }

    /**
     * @throws GitHubApiException
     */
    public function testRestoreBranchProtectionAppliesSafeDefaultsForAbsentKeys(): void
    {
        $capturedBody = null;
        $this->api->method('updateBranchProtection')->willReturnCallback(
            static function (string $branch, array $params) use (&$capturedBody): void {
                $capturedBody = $params;
            }
        );

        $this->service->restoreBranchProtection('main', new BranchProtection(
            requiredStatusChecks: new RequiredStatusChecks(contexts: []),
            requiredPullRequestReviews: new RequiredPullRequestReviews(),
        ));

        self::assertIsArray($capturedBody, 'updateBranchProtection must have been called');
        self::assertSame(['strict' => false, 'contexts' => []], $capturedBody['required_status_checks']);
        self::assertSame([
            'dismiss_stale_reviews' => false,
            'require_code_owner_reviews' => false,
            'required_approving_review_count' => 0,
        ], $capturedBody['required_pull_request_reviews'], 'require_last_push_approval must be ABSENT (not just falsy) when the source key is not set');
        self::assertNull($capturedBody['restrictions']);
        self::assertFalse($capturedBody['enforce_admins']);
        self::assertFalse($capturedBody['required_linear_history']);
        self::assertFalse($capturedBody['allow_force_pushes']);
        self::assertFalse($capturedBody['allow_deletions']);
        self::assertFalse($capturedBody['block_creations']);
        self::assertFalse($capturedBody['required_conversation_resolution']);
        self::assertFalse($capturedBody['lock_branch']);
        self::assertFalse($capturedBody['allow_fork_syncing']);
    }
}
