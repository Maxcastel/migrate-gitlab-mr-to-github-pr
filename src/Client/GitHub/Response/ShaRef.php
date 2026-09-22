<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/git/commits#get-a-commit-object
 */
final readonly class ShaRef
{
    public function __construct(public string $sha) {}

    /**
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(mixed $payload, string $errorMessage): self
    {
        $sha = \is_array($payload) ? ($payload['sha'] ?? null) : null;

        if (!\is_string($sha)) {
            throw new GitHubApiException($errorMessage);
        }

        return new self($sha);
    }

    /**
     * @return list<self>
     *
     * @throws GitHubApiException
     */
    public static function buildListFromApiResponse(mixed $payload, string $errorMessage): array
    {
        if (!\is_array($payload)) {
            return [];
        }

        $refs = [];
        foreach ($payload as $entry) {
            $refs[] = self::buildFromApiResponse($entry, $errorMessage);
        }

        return $refs;
    }
}
