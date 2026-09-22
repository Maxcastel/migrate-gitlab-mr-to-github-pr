<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\ShaRef;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShaRefTest extends TestCase
{
    private const ERROR = 'a sha was expected here';

    /**
     * @throws GitHubApiException
     */
    public function testReadsTheSha(): void
    {
        self::assertSame('abc123', ShaRef::buildFromApiResponse(['sha' => 'abc123'], self::ERROR)->sha);
    }

    /**
     * @throws GitHubApiException
     */
    public function testIgnoresTheOtherFieldsOfTheReference(): void
    {
        $ref = ShaRef::buildFromApiResponse(
            ['sha' => 'abc123', 'url' => 'https://api.github.com/…', 'html_url' => 'https://github.com/…'],
            self::ERROR
        );

        self::assertSame('abc123', $ref->sha);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusablePayloadProvider(): iterable
    {
        yield 'not an object at all' => ['a raw response body'];
        yield 'object without a sha' => [['url' => 'https://api.github.com/…']];
        yield 'sha reported as a number' => [['sha' => 42]];
        yield 'sha reported as null' => [['sha' => null]];
    }

    /**
     * @throws GitHubApiException
     */
    #[DataProvider('unusablePayloadProvider')]
    public function testRejectsAnUnusableReferenceWithTheCallersMessage(mixed $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage(self::ERROR);

        ShaRef::buildFromApiResponse($payload, self::ERROR);
    }

    /**
     * @throws GitHubApiException
     */
    public function testBuildsOneReferencePerEntryInOrder(): void
    {
        $refs = ShaRef::buildListFromApiResponse(
            [['sha' => 'first'], ['sha' => 'second'], ['sha' => 'third']],
            self::ERROR
        );

        self::assertSame(['first', 'second', 'third'], array_map(
            static fn (ShaRef $ref): string => $ref->sha,
            $refs
        ), 'order matters: parents[0] is the base and parents[1] the feature tip');
    }

    /**
     * @throws GitHubApiException
     */
    public function testYieldsNoReferenceWhenTheListIsAbsent(): void
    {
        self::assertSame([], ShaRef::buildListFromApiResponse(null, self::ERROR));
    }

    /**
     * @throws GitHubApiException
     */
    public function testYieldsNoReferenceForAnEmptyList(): void
    {
        self::assertSame([], ShaRef::buildListFromApiResponse([], self::ERROR));
    }

    /**
     * @throws GitHubApiException
     */
    public function testRejectsTheWholeListWhenOneEntryHasNoSha(): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage(self::ERROR);

        ShaRef::buildListFromApiResponse([['sha' => 'first'], ['url' => 'no sha here']], self::ERROR);
    }
}
