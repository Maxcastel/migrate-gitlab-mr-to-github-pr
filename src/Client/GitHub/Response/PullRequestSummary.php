<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/pulls/pulls#list-pull-requests
 */
final readonly class PullRequestSummary
{
    public function __construct(
        public int $number,
        public string $title,
        public ?BranchRef $head,
    ) {}

    /**
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(mixed $payload): self
    {
        $data = \is_array($payload) ? $payload : [];

        $number = $data['number'] ?? null;
        $title = $data['title'] ?? null;

        if (!\is_int($number) || !\is_string($title)) {
            throw new GitHubApiException('Unexpected GitHub pull request payload: "number" must be an integer and "title" a string.');
        }

        return new self(
            $number,
            $title,
            BranchRef::buildFromApiResponse($data['head'] ?? null),
        );
    }
}
