<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/commits/commits#compare-two-commits
 */
final readonly class CommitComparison
{
    /**
     * @param list<RepoCommit> $commits
     */
    public function __construct(
        public array $commits,
        public ?ShaRef $mergeBaseCommit = null,
    ) {}

    /**
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(mixed $payload, string $base, string $head): self
    {
        $data = \is_array($payload) ? $payload : [];
        $rawCommits = $data['commits'] ?? null;

        if (!\is_array($rawCommits)) {
            throw new GitHubApiException(\sprintf('Unexpected GitHub payload comparing %s...%s: "commits" must be a list.', $base, $head));
        }

        $context = \sprintf('%s...%s', $base, $head);

        $commits = [];
        foreach ($rawCommits as $rawCommit) {
            $commits[] = RepoCommit::buildFromApiResponse($rawCommit, $context);
        }

        $rawMergeBase = $data['merge_base_commit'] ?? null;

        return new self(
            $commits,
            null === $rawMergeBase
                ? null
                : ShaRef::buildFromApiResponse($rawMergeBase, \sprintf('Unexpected GitHub payload comparing %s: "merge_base_commit.sha" must be a string.', $context)),
        );
    }
}
