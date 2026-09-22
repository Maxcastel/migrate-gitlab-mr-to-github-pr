<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MergeRequest;
use App\Entity\MergeRequestState;
use App\Exception\ImportException;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MergeRequestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function baseGitLabPayload(): array
    {
        return [
            'id' => 12345,
            'iid' => 7,
            'project_id' => 999,
            'web_url' => 'https://gitlab.com/test/repo/-/merge_requests/7',
            'title' => 'feat: add something',
            'description' => 'Description body',
            'source_branch' => 'feat-branch',
            'target_branch' => 'main',
            'state' => 'opened',
            'created_at' => '2025-09-08T10:00:00.000Z',
            'sha' => 'abc123',
        ];
    }

    public function testDefaultConstructorValuesArePinned(): void
    {
        $mr = new MergeRequest();

        self::assertSame(0, $mr->gitLabId);
        self::assertSame(0, $mr->gitLabIid);
        self::assertSame(0, $mr->gitLabProjectId);
        self::assertSame('', $mr->gitLabUrl);
        self::assertSame('', $mr->title);
        self::assertSame('', $mr->description);
        self::assertSame('', $mr->sourceBranch);
        self::assertSame('main', $mr->targetBranch, 'default target branch is main, not master');
        self::assertSame(MergeRequestState::Opened, $mr->state);
        self::assertSame(0, $mr->createdAt);
        self::assertSame(0, $mr->mergedAt);
        self::assertSame('', $mr->commitSha);
        self::assertSame('', $mr->mergeCommitSha);
        self::assertSame('', $mr->baseSha);
        self::assertSame('', $mr->originalTitle);
        self::assertSame([], $mr->labels);
        self::assertSame([], $mr->assignees);
        self::assertSame([], $mr->reviewers);
        self::assertFalse($mr->isDraft, 'isDraft must default to false');
    }

    /**
     * @throws ImportException
     */
    public function testBuildOpenedMergeRequest(): void
    {
        $mr = MergeRequest::buildFromGitLabApiResponse($this->baseGitLabPayload());

        self::assertSame(12345, $mr->gitLabId);
        self::assertSame(7, $mr->gitLabIid);
        self::assertSame(999, $mr->gitLabProjectId);
        self::assertSame('https://gitlab.com/test/repo/-/merge_requests/7', $mr->gitLabUrl);
        self::assertSame('feat: add something', $mr->title);
        self::assertSame('Description body', $mr->description);
        self::assertSame('feat-branch', $mr->sourceBranch);
        self::assertSame('main', $mr->targetBranch);
        self::assertSame(MergeRequestState::Opened, $mr->state);
        self::assertSame(strtotime('2025-09-08T10:00:00.000Z'), $mr->createdAt);
        self::assertSame(0, $mr->mergedAt);
        self::assertSame('abc123', $mr->commitSha);
        self::assertSame('', $mr->mergeCommitSha);
        self::assertSame('', $mr->baseSha);
        self::assertSame('feat: add something', $mr->originalTitle);
        self::assertSame([], $mr->labels);
        self::assertSame([], $mr->assignees);
        self::assertSame([], $mr->reviewers);
        self::assertFalse($mr->isDraft);
    }

    /**
     * @throws ImportException
     */
    public function testMissingOptionalFieldsDefaultGracefully(): void
    {
        $payload = $this->baseGitLabPayload();
        unset($payload['description'], $payload['sha'], $payload['labels'], $payload['assignees']);

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame('', $mr->description);
        self::assertSame('', $mr->commitSha);
        self::assertSame([], $mr->labels);
        self::assertSame([], $mr->assignees);
    }

    /**
     * @return Iterator<string, array{mixed, mixed, mixed}>
     */
    public static function nonIntegerIdentifierProvider(): Iterator
    {
        yield 'absent keys' => [null, null, null];
        yield 'strings' => ['12345', '7', '999'];
        yield 'floats' => [12345.6, 7.5, 999.9];
        yield 'arrays' => [['id' => 1], ['iid' => 1], ['project_id' => 1]];
    }

    /**
     * @throws ImportException
     */
    #[DataProvider('nonIntegerIdentifierProvider')]
    public function testNonIntegerIdentifiersFallBackToZero(mixed $id, mixed $iid, mixed $projectId): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['id'] = $id;
        $payload['iid'] = $iid;
        $payload['project_id'] = $projectId;

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(0, $mr->gitLabId);
        self::assertSame(0, $mr->gitLabIid);
        self::assertSame(0, $mr->gitLabProjectId);
    }

    /**
     * @throws ImportException
     */
    public function testStringPropertiesFallBackToEmptyStringWhenInvalid(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['web_url'] = ['invalid_type_array'];
        $payload['title'] = 12345;
        $payload['description'] = false;
        $payload['source_branch'] = null;
        $payload['sha'] = 456.78;
        $payload['merge_commit_sha'] = ['invalid_type_array'];

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame('', $mr->gitLabUrl);
        self::assertSame('', $mr->title);
        self::assertSame('', $mr->originalTitle);
        self::assertSame('', $mr->description);
        self::assertSame('', $mr->sourceBranch);
        self::assertSame('', $mr->commitSha);
        self::assertSame('', $mr->mergeCommitSha);
    }

    /**
     * @throws ImportException
     */
    public function testTitleStripsDraftPrefixButOriginalTitleKeepsIt(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['draft'] = true;
        $payload['title'] = 'Draft: feat: ripple effect';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame('feat: ripple effect', $mr->title);
        self::assertSame('Draft: feat: ripple effect', $mr->originalTitle);
    }

    /**
     * @throws ImportException
     */
    public function testTitleStripsLegacyWipPrefixButOriginalTitleKeepsIt(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['work_in_progress'] = true;
        $payload['title'] = 'WIP: feat: ripple effect';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame('feat: ripple effect', $mr->title);
        self::assertSame('WIP: feat: ripple effect', $mr->originalTitle);
    }

    /**
     * @throws ImportException
     */
    public function testTitleIsUnchangedWhenItDoesNotStartWithADraftPrefix(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['title'] = 'feat: handle Draft: state in API';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame('feat: handle Draft: state in API', $mr->title);
        self::assertSame('feat: handle Draft: state in API', $mr->originalTitle);
    }

    /**
     * @throws ImportException
     */
    public function testTargetBranchIsReadFromThePayloadAndNotForcedToMain(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['target_branch'] = 'develop';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame('develop', $mr->targetBranch, 'the target branch sent by GitLab must be used verbatim, not replaced by the "main" fallback');
    }

    /**
     * @throws ImportException
     */
    public function testTargetBranchFallsBackToMainWhenTheApiSendsNoString(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['target_branch'] = null;

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame('main', $mr->targetBranch);
    }

    /**
     * @throws ImportException
     */
    public function testBuildMergedMergeRequestPopulatesMergedAtAndMergeCommitSha(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['state'] = 'merged';
        $payload['merged_at'] = '2025-09-09T15:30:00.000Z';
        $payload['merge_commit_sha'] = 'merge-sha-abc';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(MergeRequestState::Merged, $mr->state);
        self::assertSame(strtotime('2025-09-09T15:30:00.000Z'), $mr->mergedAt);
        self::assertSame('merge-sha-abc', $mr->mergeCommitSha);
    }

    /**
     * @throws ImportException
     */
    public function testBuildClosedMergeRequest(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['state'] = 'closed';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(MergeRequestState::Closed, $mr->state);
        self::assertSame(0, $mr->mergedAt, 'A closed-without-merge MR should have mergedAt=0');
    }

    /**
     * @throws ImportException
     */
    public function testUnknownStateFallsBackToOpened(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['state'] = 'locked';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(MergeRequestState::Opened, $mr->state);
    }

    /**
     * @return Iterator<string, array{mixed}>
     */
    public static function nonStringDateProvider(): Iterator
    {
        yield 'absent key' => [null];
        yield 'unix timestamp as int' => [1_757_325_600];
        yield 'array' => [['date' => '2025-09-08T10:00:00.000Z']];
    }

    /**
     * @throws ImportException
     */
    #[DataProvider('nonStringDateProvider')]
    public function testNonStringCreatedAtFallBackToZero(mixed $createdAt): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['created_at'] = $createdAt;

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(0, $mr->createdAt);
    }

    /**
     * @throws ImportException
     */
    public function testUnparsableCreatedAtFallBackToZero(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['created_at'] = 'not a date';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(0, $mr->createdAt, 'strtotime() returning false must degrade to 0');
    }

    /**
     * @throws ImportException
     */
    public function testUnparsableMergedAtFallBackToZero(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['merged_at'] = 'not a date';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(0, $mr->mergedAt);
        self::assertSame(0, MergeRequest::compareByMergeOrder($mr, new MergeRequest(gitLabIid: $mr->gitLabIid)), 'an unparsable merged_at must leave the MR indistinguishable from a never-merged one');
    }

    /**
     * @throws ImportException
     */
    public function testBaseShaIsPopulatedFromDiffRefs(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['diff_refs'] = [
            'base_sha' => 'base-divergence-sha',
            'head_sha' => 'head-sha',
            'start_sha' => 'start-sha',
        ];

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame('base-divergence-sha', $mr->baseSha);
    }

    /**
     * @throws ImportException
     */
    public function testBaseShaIsEmptyWhenDiffRefsMissing(): void
    {
        $mr = MergeRequest::buildFromGitLabApiResponse($this->baseGitLabPayload());

        self::assertSame('', $mr->baseSha);
    }

    /**
     * @throws ImportException
     */
    public function testLabelsArePassedThrough(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['labels'] = ['bug', 'priority::high'];

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(['bug', 'priority::high'], $mr->labels);
    }

    /**
     * @throws ImportException
     */
    public function testAssigneesAreExtractedAsUsernames(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['assignees'] = [
            ['username' => 'alice', 'id' => 1],
            ['username' => 'bob', 'id' => 2],
            ['id' => 3],
        ];

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(['alice', 'bob'], $mr->assignees);
    }

    /**
     * @throws ImportException
     */
    public function testReviewersAreExtractedAsUsernames(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['reviewers'] = [
            ['username' => 'alice', 'id' => 1],
            ['username' => 'bob', 'id' => 2],
            ['id' => 3],
        ];

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(['alice', 'bob'], $mr->reviewers);
    }

    /**
     * @throws ImportException
     */
    public function testReviewersDefaultToEmptyArrayWhenAbsent(): void
    {
        $payload = $this->baseGitLabPayload();

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame([], $mr->reviewers);
    }

    /**
     * @throws ImportException
     */
    public function testCollectionsIgnoreInvalidTypesAndElements(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['labels'] = ['valid_label', 123, false];
        $payload['assignees'] = [
            'not-an-array',
            ['id' => 1],
            ['username' => 123],
            ['username' => 'alice'],
        ];
        $payload['reviewers'] = 'not-an-array';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertSame(['valid_label'], $mr->labels);
        self::assertSame(['alice'], $mr->assignees);
        self::assertSame([], $mr->reviewers);
    }

    /**
     * @throws ImportException
     */
    public function testIsDraftIsFalseByDefaultWhenNeitherFlagPresent(): void
    {
        $mr = MergeRequest::buildFromGitLabApiResponse($this->baseGitLabPayload());
        self::assertFalse($mr->isDraft);
    }

    /**
     * @throws ImportException
     */
    public function testIsDraftIsTrueWhenGitLabDraftFlagIsTrue(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['draft'] = true;
        $payload['title'] = 'Draft: feat: add something';

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertTrue($mr->isDraft);
    }

    /**
     * @throws ImportException
     */
    public function testIsDraftFallsBackToLegacyWorkInProgressFlag(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['work_in_progress'] = true;

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertTrue($mr->isDraft);
    }

    /**
     * @throws ImportException
     */
    public function testIsDraftPrefersDraftFieldOverWorkInProgressWhenBothPresent(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['draft'] = false;
        $payload['work_in_progress'] = true;

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertFalse($mr->isDraft, 'When `draft` is explicitly false, it must win over a legacy `work_in_progress=true`');
    }

    /**
     * @throws ImportException
     */
    public function testIsDraftCastsIntegerOneFromGitLabToBool(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['draft'] = 1;

        $mr = MergeRequest::buildFromGitLabApiResponse($payload);

        self::assertTrue($mr->isDraft, 'integer 1 from GitLab must be cast to true before reaching the bool param');
    }

    private function mr(int $iid, int $mergedAt = 0): MergeRequest
    {
        return new MergeRequest(gitLabIid: $iid, mergedAt: $mergedAt);
    }

    public function testCompareTwoMergedMrsByMergedAtAscending(): void
    {
        $earlier = $this->mr(iid: 10, mergedAt: 1000);
        $later = $this->mr(iid: 2, mergedAt: 2000);

        self::assertSame(-1, MergeRequest::compareByMergeOrder($earlier, $later));
        self::assertSame(1, MergeRequest::compareByMergeOrder($later, $earlier));
    }

    public function testMergedMrAlwaysComesBeforeNonMergedRegardlessOfIid(): void
    {
        $merged = $this->mr(iid: 99, mergedAt: 1000);
        $unmerged = $this->mr(iid: 1, mergedAt: 0);

        self::assertSame(-1, MergeRequest::compareByMergeOrder($merged, $unmerged));
        self::assertSame(1, MergeRequest::compareByMergeOrder($unmerged, $merged));
    }

    public function testTwoUnmergedMrsAreSortedByIidAscending(): void
    {
        $a = $this->mr(iid: 3, mergedAt: 0);
        $b = $this->mr(iid: 5, mergedAt: 0);

        self::assertSame(-1, MergeRequest::compareByMergeOrder($a, $b));
        self::assertSame(1, MergeRequest::compareByMergeOrder($b, $a));
    }

    public function testEqualMergedAtAndIidReturnsZero(): void
    {
        $a = $this->mr(iid: 5, mergedAt: 1000);
        $b = $this->mr(iid: 5, mergedAt: 1000);

        self::assertSame(0, MergeRequest::compareByMergeOrder($a, $b));
    }

    public function testFullSortScenarioReplicatingTheProductionBug(): void
    {
        $mrs = [
            $this->mr(iid: 1, mergedAt: 100),
            $this->mr(iid: 6, mergedAt: 500),
            $this->mr(iid: 7, mergedAt: 200),
            $this->mr(iid: 17, mergedAt: 400),
            $this->mr(iid: 30, mergedAt: 0),
            $this->mr(iid: 16, mergedAt: 0),
        ];

        usort($mrs, [MergeRequest::class, 'compareByMergeOrder']);

        $iidOrder = array_map(static fn ($mr): int => $mr->gitLabIid, $mrs);
        self::assertSame([1, 7, 17, 6, 16, 30], $iidOrder);
    }
}
