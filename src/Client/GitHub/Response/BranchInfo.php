<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

use App\Exception\Api\GitHubApiException;

/**
 * @see https://docs.github.com/rest/branches/branches#get-a-branch
 */
final readonly class BranchInfo
{
    public function __construct(
        public string $name,
        public ShaRef $commit,
    ) {}

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    public static function buildFromApiResponse(array $payload, string $branchName): self
    {
        $name = $payload['name'] ?? null;
        $commit = $payload['commit'] ?? null;
        $sha = \is_array($commit) ? ($commit['sha'] ?? null) : null;

        if (!\is_string($name) || !\is_string($sha)) {
            throw new GitHubApiException(\sprintf('Unexpected GitHub payload for branch \'%s\': "name" and "commit.sha" must be strings.', $branchName));
        }

        return new self($name, new ShaRef($sha));
    }
}
