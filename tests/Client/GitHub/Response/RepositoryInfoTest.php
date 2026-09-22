<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\RepositoryInfo;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepositoryInfoTest extends TestCase
{
    /**
     * @throws GitHubApiException
     */
    public function testKeepsTheNumericIdTheAttachmentEndpointNeeds(): void
    {
        self::assertSame(1296269, RepositoryInfo::buildFromApiResponse(['id' => 1296269, 'name' => 'ignored'])->id);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unusableRepositoryProvider(): iterable
    {
        yield 'id reported as a string' => [['id' => '1296269']];
        yield 'id absent' => [['name' => 'Hello-World']];
        yield 'id reported as null' => [['id' => null]];
        yield 'empty response' => [[]];
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    #[DataProvider('unusableRepositoryProvider')]
    public function testRejectsARepositoryItCannotAttachFilesTo(array $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload for the repository: "id" must be an integer.');

        RepositoryInfo::buildFromApiResponse($payload);
    }
}
