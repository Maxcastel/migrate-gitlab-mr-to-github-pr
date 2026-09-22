<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\RepoCommit;
use App\Client\GitHub\Response\ShaRef;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepoCommitTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private function payload(): array
    {
        return [
            'sha' => 'c',
            'html_url' => 'https://github.com/…',
            'commit' => [
                'message' => 'feat: something',
                'tree' => ['sha' => 'tree-sha'],
                'author' => ['name' => 'Alice', 'email' => 'alice@example.com', 'date' => '2025-09-08T10:00:00Z'],
                'committer' => null,
            ],
            'parents' => [['sha' => 'p1']],
        ];
    }

    /**
     * @throws GitHubApiException
     */
    public function testSeparatesTheRepositoryShaFromTheNestedGitObject(): void
    {
        $commit = RepoCommit::buildFromApiResponse($this->payload(), 'base...head');

        self::assertSame('c', $commit->sha);
        self::assertSame('feat: something', $commit->commit->message);
        self::assertSame('tree-sha', $commit->commit->tree->sha);
        self::assertNotNull($commit->parents);
        self::assertSame(['p1'], array_map(static fn (ShaRef $p): string => $p->sha, $commit->parents));
    }

    /**
     * @throws GitHubApiException
     */
    public function testYieldsNullParentsWhenGitHubDoesNotReportThem(): void
    {
        $payload = $this->payload();
        unset($payload['parents']);

        self::assertNull(RepoCommit::buildFromApiResponse($payload, 'base...head')->parents);
    }

    /**
     * @throws GitHubApiException
     */
    public function testReadsTheTwoParentsOfAMergeCommitInOrder(): void
    {
        $payload = $this->payload();
        $payload['parents'] = [['sha' => 'p0'], ['sha' => 'p1']];

        $parents = RepoCommit::buildFromApiResponse($payload, 'base...head')->parents;

        self::assertNotNull($parents);
        self::assertCount(2, $parents, 'two parents is what marks a merge commit the cherry-pick must skip');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableCommitProvider(): iterable
    {
        yield 'not an object at all' => ['c'];
        yield 'sha absent' => [['commit' => ['message' => 'm', 'tree' => ['sha' => 't']]]];
        yield 'sha reported as a number' => [['sha' => 42, 'commit' => ['message' => 'm', 'tree' => ['sha' => 't']]]];
        yield 'commit object absent' => [['sha' => 'c']];
        yield 'commit is not an object' => [['sha' => 'c', 'commit' => 'feat: something']];
    }

    /**
     * @throws GitHubApiException
     */
    #[DataProvider('unusableCommitProvider')]
    public function testRejectsACommitItCannotIdentifyAndQuotesTheComparison(mixed $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub commit payload in base...head: "sha" must be a string and "commit" an object.');

        RepoCommit::buildFromApiResponse($payload, 'base...head');
    }
}
