<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/issues/issues#create-an-issue
 */
final readonly class CreatedIssue
{
    public function __construct(public int $number) {}

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(array $payload): self
    {
        $number = $payload['number'] ?? null;

        if (!\is_int($number)) {
            throw new GitHubApiException('Unexpected GitHub payload for the created issue: "number" must be an integer.');
        }

        return new self($number);
    }
}
