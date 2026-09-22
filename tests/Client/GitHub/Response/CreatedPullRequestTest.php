<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\CreatedPullRequest;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreatedPullRequestTest extends TestCase
{
    /**
     * @throws GitHubApiException
     */
    public function testKeepsTheNumberAndTheHeadCommit(): void
    {
        $pullRequest = CreatedPullRequest::buildFromApiResponse([
            'number' => 7,
            'head' => ['sha' => 'head-sha', 'ref' => 'feat', 'label' => 'me:feat'],
        ]);

        self::assertSame(7, $pullRequest->number);
        self::assertSame('head-sha', $pullRequest->head->sha);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unusableCreatedPullRequestProvider(): iterable
    {
        yield 'number reported as a string' => [['number' => '7', 'head' => ['sha' => 'head-sha']]];
        yield 'number absent' => [['head' => ['sha' => 'head-sha']]];
        yield 'head absent' => [['number' => 7]];
        yield 'head is not an object' => [['number' => 7, 'head' => 'feat']];
        yield 'head without a sha' => [['number' => 7, 'head' => ['ref' => 'feat']]];
        yield 'head sha reported as a number' => [['number' => 7, 'head' => ['sha' => 42]]];
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    #[DataProvider('unusableCreatedPullRequestProvider')]
    public function testRejectsACreationItCannotMergeLater(array $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload for the created pull request: "number" must be an integer and "head.sha" a string.');

        CreatedPullRequest::buildFromApiResponse($payload);
    }
}
