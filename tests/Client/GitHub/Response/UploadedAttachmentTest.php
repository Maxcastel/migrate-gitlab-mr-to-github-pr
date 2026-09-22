<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\UploadedAttachment;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadedAttachmentTest extends TestCase
{
    /**
     * @throws GitHubApiException
     */
    public function testKeepsTheUrlToPutInTheDescription(): void
    {
        self::assertSame(
            'https://github.com/user-attachments/assets/bcf3a3ca-a300-45a1-b291-7d25174d12fe',
            UploadedAttachment::buildFromApiResponse([
                'id' => 42,
                'name' => 'screenshot.png',
                'url' => 'https://github.com/user-attachments/assets/bcf3a3ca-a300-45a1-b291-7d25174d12fe',
            ])->url
        );
    }

    /**
     * @throws GitHubApiException
     */
    public function testFallsBackToTheHrefFieldReturnedByTheEndpoint(): void
    {
        self::assertSame(
            'https://github.com/user-attachments/assets/8d61504a-aac2-4fcd-88f8-2a8198fa3e6f',
            UploadedAttachment::buildFromApiResponse([
                'href' => 'https://github.com/user-attachments/assets/8d61504a-aac2-4fcd-88f8-2a8198fa3e6f',
            ])->url
        );
    }

    /**
     * @throws GitHubApiException
     */
    public function testPrefersTheUrlFieldWhenBothArePresent(): void
    {
        self::assertSame(
            'https://github.com/user-attachments/assets/from-url',
            UploadedAttachment::buildFromApiResponse([
                'url' => 'https://github.com/user-attachments/assets/from-url',
                'href' => 'https://github.com/user-attachments/assets/from-href',
            ])->url
        );
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unusableAttachmentProvider(): iterable
    {
        yield 'url reported as a number' => [['url' => 42]];
        yield 'url absent' => [['id' => 42, 'name' => 'screenshot.png']];
        yield 'url reported as null' => [['url' => null]];
        yield 'url reported as an empty string' => [['url' => '']];
        yield 'href reported as an empty string' => [['href' => '']];
        yield 'empty response' => [[]];
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    #[DataProvider('unusableAttachmentProvider')]
    public function testRejectsAnUploadItCannotLinkTo(array $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload for the uploaded attachment: "url" must be a non-empty string.');

        UploadedAttachment::buildFromApiResponse($payload);
    }
}
