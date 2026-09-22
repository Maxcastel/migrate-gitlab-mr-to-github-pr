<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * Response from the endpoint the GitHub web UI calls when a file is dropped into an issue.
 *
 * @see https://github.com/orgs/community/discussions/46951
 */
final readonly class UploadedAttachment
{
    public function __construct(public string $url) {}

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(array $payload): self
    {
        $url = $payload['url'] ?? $payload['href'] ?? null;

        if (!\is_string($url) || '' === $url) {
            throw new GitHubApiException('Unexpected GitHub payload for the uploaded attachment: "url" must be a non-empty string.');
        }

        return new self($url);
    }
}
