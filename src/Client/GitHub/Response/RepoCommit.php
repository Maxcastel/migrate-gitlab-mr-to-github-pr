<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/commits/commits#compare-two-commits
 */
final readonly class RepoCommit
{
    /**
     * @param list<ShaRef>|null $parents
     */
    public function __construct(
        public string $sha,
        public RepoCommitDetail $commit,
        public ?array $parents,
    ) {}

    /**
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(mixed $payload, string $context): self
    {
        $data = \is_array($payload) ? $payload : [];

        $sha = $data['sha'] ?? null;
        $commit = $data['commit'] ?? null;

        if (!\is_string($sha) || !\is_array($commit)) {
            throw new GitHubApiException(\sprintf('Unexpected GitHub commit payload in %s: "sha" must be a string and "commit" an object.', $context));
        }

        return new self(
            $sha,
            RepoCommitDetail::buildFromApiResponse($commit, $sha, $context),
            isset($data['parents'])
                ? ShaRef::buildListFromApiResponse(
                    $data['parents'],
                    \sprintf('Unexpected GitHub payload for commit \'%s\': a parent has no "sha".', $sha)
                )
                : null,
        );
    }
}
