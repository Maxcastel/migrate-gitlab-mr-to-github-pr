<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\GitCommit;
use App\Client\GitHub\Response\ShaRef;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\TestCase;

final class GitCommitTest extends TestCase
{
    /**
     * @throws GitHubApiException
     */
    public function testReadsTheTreeParentsAndAuthorOfAMergeCommit(): void
    {
        $commit = GitCommit::buildFromApiResponse([
            'sha' => 'merge-sha',
            'tree' => ['sha' => 'tree-sha'],
            'parents' => [['sha' => 'pre-merge-base'], ['sha' => 'feature-tip']],
            'author' => ['name' => 'Alice', 'email' => 'alice@example.com'],
            'verification' => ['verified' => false],
        ], 'merge-sha');

        self::assertSame('tree-sha', $commit->tree->sha);
        self::assertSame(
            ['pre-merge-base', 'feature-tip'],
            array_map(static fn (ShaRef $p): string => $p->sha, $commit->parents),
            'parent[0] is the pre-merge base and parent[1] the real feature tip'
        );
        self::assertNotNull($commit->author);
        self::assertSame('Alice', $commit->author->name);
    }

    /**
     * @throws GitHubApiException
     */
    public function testYieldsNoParentWhenGitHubReportsNone(): void
    {
        $commit = GitCommit::buildFromApiResponse(['tree' => ['sha' => 'tree-sha']], 'root-sha');

        self::assertSame([], $commit->parents);
    }

    /**
     * @throws GitHubApiException
     */
    public function testYieldsNoAuthorWhenGitHubReportsNone(): void
    {
        $commit = GitCommit::buildFromApiResponse(['tree' => ['sha' => 'tree-sha']], 'sha1');

        self::assertNull($commit->author);
    }

    /**
     * @throws GitHubApiException
     */
    public function testRejectsACommitWithoutATreeAndQuotesIt(): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload for commit \'sha1\': "tree.sha" must be a string.');

        GitCommit::buildFromApiResponse(['parents' => []], 'sha1');
    }

    /**
     * @throws GitHubApiException
     */
    public function testRejectsACommitWhoseParentCannotBeNamed(): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload for commit \'sha1\': a parent has no "sha".');

        GitCommit::buildFromApiResponse([
            'tree' => ['sha' => 'tree-sha'],
            'parents' => [['sha' => 'ok'], ['url' => 'no sha here']],
        ], 'sha1');
    }
}
