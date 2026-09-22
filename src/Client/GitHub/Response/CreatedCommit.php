<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/git/commits#create-a-commit
 */
final readonly class CreatedCommit
{
    public function __construct(public string $sha) {}

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(array $payload): self
    {
        $sha = $payload['sha'] ?? null;

        if (!\is_string($sha)) {
            throw new GitHubApiException('Unexpected GitHub payload for the created commit: "sha" must be a string.');
        }

        return new self($sha);
    }
}
