<?php

declare(strict_types=1);

namespace App\Service;

use App\Client\GitLab\GitLabApiClient;
use App\Entity\Issue;
use App\Entity\MergeRequest;
use App\Exception\ImportException;
use Exception;
use Http\Client\Exception as HttpClientException;
use UnhandledMatchError;

class GitLabService
{
    public function __construct(
        private GitLabApiClient $api = new GitLabApiClient(),
    ) {}

    /**
     * @throws ImportException
     */
    public function init(
        string $token,
        bool $skipSslCertificateVerification = false,
    ): void {
        try {
            $this->api->connect($token, $skipSslCertificateVerification);
        } catch (Exception $exception) {
            throw new ImportException('Error authenticating at GitLab: '.$exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    /**
     * @return array<Issue>
     *
     * @throws ImportException
     * @throws UnhandledMatchError
     * @throws HttpClientException
     */
    public function getIssues(int $projectId): array
    {
        try {
            $issues = $this->api->fetchAllIssues($projectId);
        } catch (Exception $exception) {
            throw new ImportException('Error retrieving GitLab issues: '.$exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return array_reverse(array_map(Issue::buildFromGitLabApiResponse(...), $issues));
    }

    /**
     * @return array<MergeRequest>
     *
     * @throws ImportException
     * @throws HttpClientException
     */
    public function getMergeRequests(int $projectId): array
    {
        try {
            $mergeRequests = $this->api->fetchAllMergeRequests($projectId);
        } catch (Exception $exception) {
            throw new ImportException('Error retrieving GitLab merge requests: '.$exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $mappedMRs = array_map(MergeRequest::buildFromGitLabApiResponse(...), $mergeRequests);

        usort($mappedMRs, [MergeRequest::class, 'compareByMergeOrder']);

        foreach ($mappedMRs as $mr) {
            if (empty($mr->mergeCommitSha) && empty($mr->baseSha)) {
                try {
                    $detail = $this->api->showMergeRequest($projectId, $mr->gitLabIid);
                    $diffRefs = $detail['diff_refs'] ?? null;
                    $baseSha = \is_array($diffRefs) ? ($diffRefs['base_sha'] ?? null) : null;
                    $mr->baseSha = \is_string($baseSha) ? $baseSha : '';
                } catch (Exception) {
                }
            }
        }

        return $mappedMRs;
    }

    /**
     * @throws ImportException
     */
    public function downloadUpload(int $projectId, string $secret, string $fileName): string
    {
        try {
            $contents = $this->api->downloadUpload($projectId, $secret, $fileName);
        } catch (HttpClientException|Exception $exception) {
            throw new ImportException(\sprintf("Error downloading the GitLab upload '%s': %s", $fileName, $exception->getMessage()), (int) $exception->getCode(), $exception);
        }

        if ('' === $contents) {
            throw new ImportException(\sprintf("The GitLab upload '%s' is empty", $fileName));
        }

        return $contents;
    }

    /**
     * @throws ImportException
     */
    public function getBranchHeadSha(int $projectId, string $branchName): string
    {
        try {
            $branch = $this->api->getBranch($projectId, $branchName);
        } catch (Exception $exception) {
            throw new ImportException(\sprintf("Error retrieving HEAD SHA of branch '%s' on GitLab: %s", $branchName, $exception->getMessage()), (int) $exception->getCode(), $exception);
        }

        $commit = $branch['commit'] ?? null;
        $headSha = \is_array($commit) ? ($commit['id'] ?? null) : null;

        return \is_string($headSha) ? $headSha : '';
    }
}
