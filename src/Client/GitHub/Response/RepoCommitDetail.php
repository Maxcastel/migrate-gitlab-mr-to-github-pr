<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/commits/commits#compare-two-commits
 */
final readonly class RepoCommitDetail
{
    /**
     * @param array<mixed>|null $author
     * @param array<mixed>|null $committer
     */
    public function __construct(
        public string $message,
        public ShaRef $tree,
        public ?array $author,
        public ?array $committer,
    ) {}

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(array $payload, string $sha, string $context): self
    {
        $message = $payload['message'] ?? null;
        $tree = $payload['tree'] ?? null;
        $treeSha = \is_array($tree) ? ($tree['sha'] ?? null) : null;

        if (!\is_string($message) || !\is_string($treeSha)) {
            throw new GitHubApiException(\sprintf('Unexpected GitHub payload for commit \'%s\' in %s: "commit.message" and "commit.tree.sha" are required.', $sha, $context));
        }

        $author = $payload['author'] ?? null;
        $committer = $payload['committer'] ?? null;

        return new self(
            $message,
            new ShaRef($treeSha),
            \is_array($author) ? $author : null,
            \is_array($committer) ? $committer : null,
        );
    }
}
