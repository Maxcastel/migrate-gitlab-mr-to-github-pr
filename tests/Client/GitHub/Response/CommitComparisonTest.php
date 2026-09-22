<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\CommitComparison;
use App\Client\GitHub\Response\RepoCommit;
use App\Client\GitHub\Response\ShaRef;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommitComparisonTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private function commitPayload(string $sha, string $message): array
    {
        return [
            'sha' => $sha,
            'commit' => ['message' => $message, 'tree' => ['sha' => 'tree-'.$sha], 'author' => null, 'committer' => null],
            'parents' => [['sha' => 'parent-of-'.$sha]],
        ];
    }

    /**
     * @throws GitHubApiException
     */
    public function testKeepsTheCommitsInTheOrderTheyMustBeReplayed(): void
    {
        $comparison = CommitComparison::buildFromApiResponse([
            'status' => 'ahead',
            'commits' => [$this->commitPayload('a', 'first'), $this->commitPayload('b', 'second')],
        ], 'base', 'head');

        self::assertSame(['first', 'second'], array_map(
            static fn (RepoCommit $c): string => $c->commit->message,
            $comparison->commits
        ), 'the rebase replays them in order, each on top of the previous one');
    }

    /**
     * @throws GitHubApiException
     */
    public function testAcceptsAComparisonWithNothingToReplay(): void
    {
        self::assertSame([], CommitComparison::buildFromApiResponse(['commits' => []], 'base', 'head')->commits);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableComparisonProvider(): iterable
    {
        yield 'not an object at all' => ['diverged'];
        yield 'no commits key' => [['status' => 'diverged']];
        yield 'commits is not a list' => [['commits' => 'none']];
    }

    /**
     * @throws GitHubApiException
     */
    #[DataProvider('unusableComparisonProvider')]
    public function testRejectsAComparisonWithoutACommitListAndQuotesBothRefs(mixed $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload comparing base...head: "commits" must be a list.');

        CommitComparison::buildFromApiResponse($payload, 'base', 'head');
    }

    /**
     * @throws GitHubApiException
     */
    public function testRejectsTheWholeComparisonWhenOneCommitIsUnusable(): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub commit payload in base...head:');

        CommitComparison::buildFromApiResponse([
            'commits' => [$this->commitPayload('a', 'first'), ['sha' => 'b']],
        ], 'base', 'head');
    }

    /**
     * @throws GitHubApiException
     */
    public function testExposesTheMergeBaseTheBranchMustBeReplayedOn(): void
    {
        $comparison = CommitComparison::buildFromApiResponse([
            'status' => 'diverged',
            'commits' => [$this->commitPayload('a', 'first')],
            'merge_base_commit' => ['sha' => 'divergence-point', 'commit' => ['message' => 'where they parted']],
        ], 'base', 'head');

        self::assertInstanceOf(ShaRef::class, $comparison->mergeBaseCommit);
        self::assertSame('divergence-point', $comparison->mergeBaseCommit->sha);
    }

    /**
     * @throws GitHubApiException
     */
    public function testLeavesTheMergeBaseNullWhenTheApiOmitsIt(): void
    {
        self::assertNull(CommitComparison::buildFromApiResponse(['commits' => []], 'base', 'head')->mergeBaseCommit);
    }

    /**
     * @throws GitHubApiException
     */
    public function testRejectsAMergeBaseWithoutASha(): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload comparing base...head: "merge_base_commit.sha" must be a string.');

        CommitComparison::buildFromApiResponse([
            'commits' => [],
            'merge_base_commit' => ['commit' => ['message' => 'no sha here']],
        ], 'base', 'head');
    }
}
