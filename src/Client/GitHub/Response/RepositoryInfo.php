<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/repos/repos#get-a-repository
 */
final readonly class RepositoryInfo
{
    public function __construct(public int $id) {}

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(array $payload): self
    {
        $id = $payload['id'] ?? null;

        if (!\is_int($id)) {
            throw new GitHubApiException('Unexpected GitHub payload for the repository: "id" must be an integer.');
        }

        return new self($id);
    }
}
