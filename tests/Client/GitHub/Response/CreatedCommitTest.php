<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\CreatedCommit;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreatedCommitTest extends TestCase
{
    /**
     * @throws GitHubApiException
     */
    public function testKeepsTheShaTheNextRefUpdateChainsOn(): void
    {
        self::assertSame('new-sha', CreatedCommit::buildFromApiResponse(['sha' => 'new-sha', 'message' => 'ignored'])->sha);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unusableCreatedCommitProvider(): iterable
    {
        yield 'sha reported as a number' => [['sha' => 42]];
        yield 'sha absent' => [['message' => 'Written but unnamed']];
        yield 'sha reported as null' => [['sha' => null]];
        yield 'empty response' => [[]];
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    #[DataProvider('unusableCreatedCommitProvider')]
    public function testRejectsACommitItCannotPointABranchAt(array $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload for the created commit: "sha" must be a string.');

        CreatedCommit::buildFromApiResponse($payload);
    }
}
