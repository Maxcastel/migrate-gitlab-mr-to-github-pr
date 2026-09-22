<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\IssueSummary;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IssueSummaryTest extends TestCase
{
    private const ERROR = 'Unexpected GitHub issue payload: "number" must be an integer and "title" a string.';

    /**
     * @throws GitHubApiException
     */
    public function testKeepsTheNumberAndTitleTheImportNeeds(): void
    {
        $summary = IssueSummary::buildFromApiResponse([
            'number' => 7,
            'title' => 'Bug: thing is broken',
            'state' => 'open',
            'body' => 'ignored',
        ]);

        self::assertSame(7, $summary->number);
        self::assertSame('Bug: thing is broken', $summary->title);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableIssueProvider(): iterable
    {
        yield 'number reported as a string' => [['number' => '7', 'title' => 'A usable title']];
        yield 'number absent' => [['title' => 'A usable title']];
        yield 'title reported as a number' => [['number' => 7, 'title' => 42]];
        yield 'title absent' => [['number' => 7]];
        yield 'not an object at all' => ['a raw response body'];
    }

    /**
     * @throws GitHubApiException
     */
    #[DataProvider('unusableIssueProvider')]
    public function testRejectsAnIssueItCannotIdentify(mixed $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage(self::ERROR);

        IssueSummary::buildFromApiResponse($payload);
    }
}
