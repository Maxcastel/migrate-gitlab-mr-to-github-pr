<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\PullRequestSummary;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PullRequestSummaryTest extends TestCase
{
    /**
     * @throws GitHubApiException
     */
    public function testKeepsTheNumberTitleAndSourceBranch(): void
    {
        $summary = PullRequestSummary::buildFromApiResponse([
            'number' => 3,
            'title' => 'feat: add ripple',
            'head' => ['ref' => 'feat-ripple', 'sha' => 'abc123'],
        ]);

        self::assertSame(3, $summary->number);
        self::assertSame('feat: add ripple', $summary->title);
        self::assertNotNull($summary->head);
        self::assertSame('feat-ripple', $summary->head->ref);
    }

    /**
     * @throws GitHubApiException
     */
    public function testStaysUsableWhenTheHeadCannotBeRead(): void
    {
        $summary = PullRequestSummary::buildFromApiResponse(['number' => 4, 'title' => 'No head reported']);

        self::assertSame(4, $summary->number);
        self::assertNull($summary->head);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusablePullRequestProvider(): iterable
    {
        yield 'number reported as a string' => [['number' => '3', 'title' => 'A usable title']];
        yield 'number absent' => [['title' => 'A usable title']];
        yield 'title reported as a number' => [['number' => 3, 'title' => 42]];
        yield 'title absent' => [['number' => 3]];
        yield 'not an object at all' => ['a raw response body'];
    }

    /**
     * @throws GitHubApiException
     */
    #[DataProvider('unusablePullRequestProvider')]
    public function testRejectsAPullRequestItCannotIdentify(mixed $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub pull request payload: "number" must be an integer and "title" a string.');

        PullRequestSummary::buildFromApiResponse($payload);
    }
}
