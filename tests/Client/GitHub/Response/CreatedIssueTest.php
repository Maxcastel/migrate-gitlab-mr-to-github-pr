<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\CreatedIssue;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreatedIssueTest extends TestCase
{
    /**
     * @throws GitHubApiException
     */
    public function testKeepsTheNumberEveryFollowUpCallNeeds(): void
    {
        self::assertSame(100, CreatedIssue::buildFromApiResponse(['number' => 100, 'title' => 'ignored'])->number);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unusableCreatedIssueProvider(): iterable
    {
        yield 'number reported as a string' => [['number' => '100']];
        yield 'number absent' => [['title' => 'Created but unnamed']];
        yield 'number reported as null' => [['number' => null]];
        yield 'empty response' => [[]];
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    #[DataProvider('unusableCreatedIssueProvider')]
    public function testRejectsACreationItCannotFollowUpOn(array $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload for the created issue: "number" must be an integer.');

        CreatedIssue::buildFromApiResponse($payload);
    }
}
