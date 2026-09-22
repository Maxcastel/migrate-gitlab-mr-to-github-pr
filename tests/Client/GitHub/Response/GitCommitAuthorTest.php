<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\GitCommitAuthor;
use PHPUnit\Framework\TestCase;

final class GitCommitAuthorTest extends TestCase
{
    public function testReadsTheGitIdentity(): void
    {
        $author = GitCommitAuthor::buildFromApiResponse([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'date' => '2025-09-08T10:00:00Z',
        ]);

        self::assertNotNull($author);
        self::assertSame('Alice', $author->name);
        self::assertSame('alice@example.com', $author->email);
    }

    public function testKeepsAnIdentityWhoseFieldsAreUnreadableRatherThanDroppingIt(): void
    {
        $author = GitCommitAuthor::buildFromApiResponse(['name' => 42, 'email' => null]);

        self::assertNotNull($author);
        self::assertNull($author->name);
        self::assertNull($author->email);
    }

    public function testReadsEachFieldIndependently(): void
    {
        $author = GitCommitAuthor::buildFromApiResponse(['name' => 'Alice']);

        self::assertNotNull($author);
        self::assertSame('Alice', $author->name);
        self::assertNull($author->email);
    }

    public function testYieldsNothingWhenNoAuthorObjectIsReported(): void
    {
        self::assertNull(GitCommitAuthor::buildFromApiResponse(null));
        self::assertNull(GitCommitAuthor::buildFromApiResponse('Alice <alice@example.com>'));
    }
}
