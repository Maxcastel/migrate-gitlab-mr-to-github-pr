<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\RepoCommitDetail;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepoCommitDetailTest extends TestCase
{
    /**
     * @throws GitHubApiException
     */
    public function testForwardsTheIdentitiesVerbatimIncludingTheirDate(): void
    {
        $author = ['name' => 'Alice', 'email' => 'alice@example.com', 'date' => '2025-09-08T10:00:00Z'];
        $committer = ['name' => 'Bob', 'email' => 'bob@example.com', 'date' => '2025-09-09T11:00:00Z'];

        $detail = RepoCommitDetail::buildFromApiResponse([
            'message' => 'feat: something',
            'tree' => ['sha' => 'tree-sha'],
            'author' => $author,
            'committer' => $committer,
        ], 'c', 'base...head');

        self::assertSame('feat: something', $detail->message);
        self::assertSame('tree-sha', $detail->tree->sha);
        self::assertSame($author, $detail->author);
        self::assertSame($committer, $detail->committer);
    }

    /**
     * @throws GitHubApiException
     */
    public function testAcceptsCommitsGitHubHoldsNoIdentityFor(): void
    {
        $detail = RepoCommitDetail::buildFromApiResponse([
            'message' => 'chore: imported without identity',
            'tree' => ['sha' => 'tree-sha'],
            'author' => null,
            'committer' => null,
        ], 'c', 'base...head');

        self::assertNull($detail->author);
        self::assertNull($detail->committer);
    }

    /**
     * @throws GitHubApiException
     */
    public function testTreatsAnUnreadableIdentityAsAbsent(): void
    {
        $detail = RepoCommitDetail::buildFromApiResponse([
            'message' => 'chore: odd payload',
            'tree' => ['sha' => 'tree-sha'],
            'author' => 'Alice <alice@example.com>',
        ], 'c', 'base...head');

        self::assertNull($detail->author);
        self::assertNull($detail->committer);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unusableDetailProvider(): iterable
    {
        yield 'message absent' => [['tree' => ['sha' => 'tree-sha']]];
        yield 'message reported as a number' => [['message' => 42, 'tree' => ['sha' => 'tree-sha']]];
        yield 'tree absent' => [['message' => 'feat: something']];
        yield 'tree is not an object' => [['message' => 'feat: something', 'tree' => 'tree-sha']];
        yield 'tree without a sha' => [['message' => 'feat: something', 'tree' => ['url' => 'https://api.github.com/…']]];
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    #[DataProvider('unusableDetailProvider')]
    public function testRejectsACommitItCouldNotReplayAndQuotesShaAndContext(array $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload for commit \'c\' in base...head: "commit.message" and "commit.tree.sha" are required.');

        RepoCommitDetail::buildFromApiResponse($payload, 'c', 'base...head');
    }
}
