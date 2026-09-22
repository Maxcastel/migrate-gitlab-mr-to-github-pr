<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/git/commits#get-a-commit-object
 */
final readonly class GitCommit
{
    /**
     * @param list<ShaRef> $parents
     */
    public function __construct(
        public ShaRef $tree,
        public array $parents,
        public ?GitCommitAuthor $author,
    ) {}

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(array $payload, string $sha): self
    {
        return new self(
            ShaRef::buildFromApiResponse(
                $payload['tree'] ?? null,
                \sprintf('Unexpected GitHub payload for commit \'%s\': "tree.sha" must be a string.', $sha)
            ),
            ShaRef::buildListFromApiResponse(
                $payload['parents'] ?? null,
                \sprintf('Unexpected GitHub payload for commit \'%s\': a parent has no "sha".', $sha)
            ),
            GitCommitAuthor::buildFromApiResponse($payload['author'] ?? null),
        );
    }
}
