<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Client\GitLab\GitLabApiClient;
use App\Entity\IssueState;
use App\Entity\MergeRequest;
use App\Entity\MergeRequestState;
use App\Exception\ImportException;
use App\Service\GitLabService;
use Exception;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\InvocationStubber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use UnhandledMatchError;

final class GitLabServiceTest extends TestCase
{
    private GitLabApiClient&Stub $api;

    #[Override]
    protected function setUp(): void
    {
        $this->api = self::createStub(GitLabApiClient::class);
    }

    private function service(): GitLabService
    {
        return new GitLabService($this->api);
    }

    private function apiMock(): GitLabApiClient&MockObject
    {
        $mock = $this->createMock(GitLabApiClient::class);
        $this->api = $mock;

        return $mock;
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     * @throws \Http\Client\Exception
     */
    public function testGetIssuesFetchesTheProjectIssuesExactlyOnce(): void
    {
        $api = $this->apiMock();
        $api->expects(self::once())->method('fetchAllIssues')->with(42)->willReturn([]);

        self::assertSame([], $this->service()->getIssues(42));
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     * @throws \Http\Client\Exception
     */
    public function testGetIssuesMapsAndReversesOrder(): void
    {
        $this->api->method('fetchAllIssues')->willReturn([
            ['id' => 3, 'iid' => 3, 'project_id' => 1, 'web_url' => 'u3',
                'title' => 'Third', 'description' => '', 'state' => 'opened', 'created_at' => '2025-09-03T00:00:00Z'],
            ['id' => 2, 'iid' => 2, 'project_id' => 1, 'web_url' => 'u2',
                'title' => 'Second', 'description' => '', 'state' => 'opened', 'created_at' => '2025-09-02T00:00:00Z'],
            ['id' => 1, 'iid' => 1, 'project_id' => 1, 'web_url' => 'u1',
                'title' => 'First', 'description' => '', 'state' => 'closed', 'created_at' => '2025-09-01T00:00:00Z'],
        ]);

        $issues = $this->service()->getIssues(1);

        self::assertCount(3, $issues);
        self::assertSame('First', $issues[0]->title);
        self::assertSame('Second', $issues[1]->title);
        self::assertSame('Third', $issues[2]->title);
        self::assertSame(IssueState::Closed, $issues[0]->state);
        self::assertSame(IssueState::Open, $issues[1]->state);
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     * @throws \Http\Client\Exception
     */
    public function testGetIssuesWrapsExceptionsWithContext(): void
    {
        $this->api->method('fetchAllIssues')->willThrowException(new RuntimeException('boom'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Error retrieving GitLab issues: boom');

        $this->service()->getIssues(42);
    }

    /**
     * @throws ImportException
     * @throws \Http\Client\Exception
     */
    public function testGetMergeRequestsFetchesTheProjectMergeRequestsExactlyOnce(): void
    {
        $api = $this->apiMock();
        $api->expects(self::once())->method('fetchAllMergeRequests')->with(42)->willReturn([]);

        self::assertSame([], $this->service()->getMergeRequests(42));
    }

    /**
     * @throws ImportException
     * @throws \Http\Client\Exception
     */
    public function testGetMergeRequestsSortsByMergedAtAndPlacesNonMergedLast(): void
    {
        $this->api->method('fetchAllMergeRequests')->willReturn([
            $this->mrPayload(iid: 6, state: 'merged', mergedAt: '2025-09-20T12:00:00Z'),
            $this->mrPayload(iid: 7, state: 'merged', mergedAt: '2025-09-10T12:00:00Z'),
            $this->mrPayload(iid: 30, state: 'opened'),
            $this->mrPayload(iid: 17, state: 'merged', mergedAt: '2025-09-15T12:00:00Z'),
        ]);
        $this->api->method('showMergeRequest')->willReturn([]);

        $mrs = $this->service()->getMergeRequests(1);

        $iidOrder = array_map(static fn (MergeRequest $mr): int => $mr->gitLabIid, $mrs);
        self::assertSame([7, 17, 6, 30], $iidOrder, 'MRs must be in (merged-by-mergedAt) then (unmerged-by-iid) order');
    }

    /**
     * @throws ImportException
     * @throws \Http\Client\Exception
     */
    public function testGetMergeRequestsFetchesDiffRefsLazilyForNonMergedMRs(): void
    {
        $api = $this->apiMock();
        $api->method('fetchAllMergeRequests')->willReturn([
            $this->mrPayload(iid: 1, state: 'merged', mergedAt: '2025-09-10T00:00:00Z', mergeCommitSha: 'abc'),
            $this->mrPayload(iid: 2, state: 'opened'),
        ]);
        $api->expects(self::once())
            ->method('showMergeRequest')
            ->with(1, 2)
            ->willReturn(['iid' => 2, 'diff_refs' => ['base_sha' => 'divergence-sha-from-detail']]);

        $mrs = $this->service()->getMergeRequests(1);

        $opened = array_values(array_filter($mrs, static fn (MergeRequest $m): bool => MergeRequestState::Opened === $m->state));
        self::assertCount(1, $opened);
        self::assertSame('divergence-sha-from-detail', $opened[0]->baseSha);
    }

    /**
     * @throws ImportException
     * @throws \Http\Client\Exception
     */
    public function testGetMergeRequestsFallsBackToEmptyBaseShaWhenDiffRefsMissing(): void
    {
        $api = $this->apiMock();
        $api->method('fetchAllMergeRequests')->willReturn([$this->mrPayload(iid: 2, state: 'opened')]);
        $api->expects(self::once())->method('showMergeRequest')->with(1, 2)->willReturn(['iid' => 2]);

        $mrs = $this->service()->getMergeRequests(1);

        self::assertSame('', $mrs[0]->baseSha);
    }

    /**
     * @throws ImportException
     * @throws \Http\Client\Exception
     */
    public function testGetMergeRequestsSwallowsErrorsFromLazyDiffRefsFetch(): void
    {
        $this->api->method('fetchAllMergeRequests')->willReturn([$this->mrPayload(iid: 2, state: 'opened')]);
        $this->api->method('showMergeRequest')->willThrowException(new RuntimeException('network down'));

        $mrs = $this->service()->getMergeRequests(1);

        self::assertCount(1, $mrs);
        self::assertSame('', $mrs[0]->baseSha);
    }

    /**
     * @throws ImportException
     * @throws \Http\Client\Exception
     */
    public function testGetMergeRequestsDoesNotRefetchForMergedMRs(): void
    {
        $api = $this->apiMock();
        $api->method('fetchAllMergeRequests')->willReturn([
            $this->mrPayload(iid: 1, state: 'merged', mergedAt: '2025-09-10T00:00:00Z', mergeCommitSha: 'abc'),
        ]);
        $api->expects(self::never())->method('showMergeRequest');

        $this->service()->getMergeRequests(1);
    }

    /**
     * @throws ImportException
     * @throws \Http\Client\Exception
     */
    public function testGetMergeRequestsWrapsExceptionsWithContext(): void
    {
        $this->api->method('fetchAllMergeRequests')->willThrowException(new RuntimeException('nope'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Error retrieving GitLab merge requests: nope');

        $this->service()->getMergeRequests(42);
    }

    /**
     * @throws ImportException
     */
    public function testInitConnectsTheApiClientWithTheGivenToken(): void
    {
        $api = $this->apiMock();
        $api->expects(self::once())->method('connect')->with('my-gitlab-token', true);

        $this->service()->init('my-gitlab-token', true);
    }

    /**
     * @throws ImportException
     */
    public function testInitDefaultsSkipSslToFalseSoVerifyIsEnabled(): void
    {
        $api = $this->apiMock();
        $api->expects(self::once())->method('connect')->with('t', false);

        $this->service()->init('t');
    }

    /**
     * @throws ImportException
     */
    public function testInitWrapsAuthenticationExceptions(): void
    {
        $this->api->method('connect')->willThrowException(new RuntimeException('upstream init failure'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Error authenticating at GitLab: upstream init failure');

        $this->service()->init('t');
    }

    /**
     * @throws ImportException
     */
    public function testGetBranchHeadShaReturnsTheCommitIdFromTheBranchPayload(): void
    {
        $api = $this->apiMock();
        $api->expects(self::once())
            ->method('getBranch')
            ->with(42, 'main')
            ->willReturn(['name' => 'main', 'commit' => ['id' => 'head-sha-on-gitlab']]);

        self::assertSame('head-sha-on-gitlab', $this->service()->getBranchHeadSha(42, 'main'));
    }

    /**
     * @throws ImportException
     */
    public function testGetBranchHeadShaReturnsEmptyStringWhenCommitIdAbsent(): void
    {
        $this->api->method('getBranch')->willReturn(['name' => 'main']);

        self::assertSame('', $this->service()->getBranchHeadSha(42, 'main'));
    }

    /**
     * @throws ImportException
     */
    public function testGetBranchHeadShaWrapsExceptionsWithBranchNameContext(): void
    {
        $this->api->method('getBranch')->willThrowException(new RuntimeException('boom'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("HEAD SHA of branch 'main' on GitLab");
        $this->expectExceptionMessage('boom');

        $this->service()->getBranchHeadSha(42, 'main');
    }

    /**
     * @throws ImportException
     */
    public function testDownloadUploadReturnsTheFileTheApiAnswered(): void
    {
        $api = $this->apiMock();
        $api->expects(self::once())->method('downloadUpload')
            ->with(42, '6fbaec24b893e88102ca3778336b36c3', 'shot.png')
            ->willReturn('PNG-BYTES');

        self::assertSame('PNG-BYTES', $this->service()->downloadUpload(42, '6fbaec24b893e88102ca3778336b36c3', 'shot.png'));
    }

    /**
     * @throws ImportException
     */
    public function testDownloadUploadWrapsExceptionsWithTheFileNameContext(): void
    {
        $this->api->method('downloadUpload')->willThrowException(new RuntimeException('404 Not Found'));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage("Error downloading the GitLab upload 'shot.png'");
        $this->expectExceptionMessage('404 Not Found');

        $this->service()->downloadUpload(42, '6fbaec24b893e88102ca3778336b36c3', 'shot.png');
    }

    /**
     * @throws ImportException
     */
    public function testDownloadUploadRejectsAnEmptyFile(): void
    {
        $this->api->method('downloadUpload')->willReturn('');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage("The GitLab upload 'shot.png' is empty");

        $this->service()->downloadUpload(42, '6fbaec24b893e88102ca3778336b36c3', 'shot.png');
    }

    /**
     * @return iterable<string, array{callable(GitLabService): mixed, callable(GitLabApiClient&Stub, Throwable): mixed}>
     */
    public static function wrappingCases(): iterable
    {
        yield 'init' => [
            static fn (GitLabService $service) => $service->init('t'),
            static fn (GitLabApiClient&Stub $api, Throwable $e): InvocationStubber => $api->method('connect')->willThrowException($e),
        ];
        yield 'getIssues' => [
            static fn (GitLabService $service): array => $service->getIssues(42),
            static fn (GitLabApiClient&Stub $api, Throwable $e): InvocationStubber => $api->method('fetchAllIssues')->willThrowException($e),
        ];
        yield 'getMergeRequests' => [
            static fn (GitLabService $service): array => $service->getMergeRequests(42),
            static fn (GitLabApiClient&Stub $api, Throwable $e): InvocationStubber => $api->method('fetchAllMergeRequests')->willThrowException($e),
        ];
        yield 'getBranchHeadSha' => [
            static fn (GitLabService $service): string => $service->getBranchHeadSha(42, 'main'),
            static fn (GitLabApiClient&Stub $api, Throwable $e): InvocationStubber => $api->method('getBranch')->willThrowException($e),
        ];
        yield 'downloadUpload' => [
            static fn (GitLabService $service): string => $service->downloadUpload(42, '6fbaec24b893e88102ca3778336b36c3', 'shot.png'),
            static fn (GitLabApiClient&Stub $api, Throwable $e): InvocationStubber => $api->method('downloadUpload')->willThrowException($e),
        ];
    }

    #[DataProvider('wrappingCases')]
    public function testWrappersChainTheUpstreamExceptionAndPropagateItsCode(
        callable $call,
        callable $arrangeFailure,
    ): void {
        $upstream = new RuntimeException('upstream failure', 503);
        $arrangeFailure($this->api, $upstream);

        try {
            $call($this->service());
            self::fail('the upstream failure must be wrapped in an ImportException');
        } catch (ImportException $importException) {
            self::assertSame($upstream, $importException->getPrevious(), 'the upstream exception must be chained as previous');
            self::assertSame(503, $importException->getCode(), "the upstream exception's code must be propagated");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mrPayload(int $iid, string $state, ?string $mergedAt = null, string $mergeCommitSha = ''): array
    {
        return [
            'id' => $iid * 1000,
            'iid' => $iid,
            'project_id' => 1,
            'web_url' => 'https://gitlab.com/test/-/merge_requests/'.$iid,
            'title' => 'MR '.$iid,
            'description' => '',
            'source_branch' => 'feat-'.$iid,
            'target_branch' => 'main',
            'state' => $state,
            'created_at' => '2025-09-01T00:00:00Z',
            'merged_at' => $mergedAt,
            'sha' => 'sha-'.$iid,
            'merge_commit_sha' => $mergeCommitSha,
        ];
    }
}
