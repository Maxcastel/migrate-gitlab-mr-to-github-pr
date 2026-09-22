<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/pulls/pulls#create-a-pull-request
 */
final readonly class CreatedPullRequest
{
    public function __construct(
        public int $number,
        public ShaRef $head,
    ) {}

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(array $payload): self
    {
        $number = $payload['number'] ?? null;
        $head = $payload['head'] ?? null;
        $headSha = \is_array($head) ? ($head['sha'] ?? null) : null;

        if (!\is_int($number) || !\is_string($headSha)) {
            throw new GitHubApiException('Unexpected GitHub payload for the created pull request: "number" must be an integer and "head.sha" a string.');
        }

        return new self($number, new ShaRef($headSha));
    }
}
