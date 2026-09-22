<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\ImportException;

class MergeRequest
{
    /**
     * @param list<string> $labels
     * @param list<string> $assignees
     * @param list<string> $reviewers
     */
    public function __construct(
        public int $gitLabId = 0,
        public int $gitLabIid = 0,
        public int $gitLabProjectId = 0,
        public string $gitLabUrl = '',
        public string $title = '',
        public string $description = '',
        public string $sourceBranch = '',
        public string $targetBranch = 'main',
        public MergeRequestState $state = MergeRequestState::Opened,
        public int $createdAt = 0,
        public int $mergedAt = 0,
        public string $commitSha = '',
        public string $mergeCommitSha = '',
        public string $baseSha = '',
        public string $originalTitle = '',
        public array $labels = [],
        public array $assignees = [],
        public array $reviewers = [],
        public bool $isDraft = false,
    ) {}

    /**
     * @param array<mixed> $data
     *
     * @throws ImportException
     */
    public static function buildFromGitLabApiResponse(array $data): self
    {
        $gitLabId = $data['id'] ?? null;
        $gitLabIid = $data['iid'] ?? null;
        $gitLabProjectId = $data['project_id'] ?? null;

        $gitLabUrl = $data['web_url'] ?? null;

        $title = $data['title'] ?? null;
        $title = \is_string($title) ? $title : '';

        $cleanedTitle = preg_replace('/^(Draft:|WIP:) /', '', $title) ?? $title;

        $description = $data['description'] ?? null;
        $sourceBranch = $data['source_branch'] ?? null;
        $targetBranch = $data['target_branch'] ?? null;

        $state = match ($data['state'] ?? '') {
            'merged' => MergeRequestState::Merged,
            'closed' => MergeRequestState::Closed,
            default => MergeRequestState::Opened,
        };

        $createdAt = $data['created_at'] ?? null;
        $mergedAt = $data['merged_at'] ?? null;

        $commitSha = $data['sha'] ?? null;
        $mergeCommitSha = $data['merge_commit_sha'] ?? null;

        $diffRefs = $data['diff_refs'] ?? null;
        $baseSha = \is_array($diffRefs) ? ($diffRefs['base_sha'] ?? null) : null;

        $dataLabels = $data['labels'] ?? null;
        $labels = [];
        if (\is_array($dataLabels)) {
            foreach ($dataLabels as $label) {
                if (\is_string($label)) {
                    $labels[] = $label;
                }
            }
        }

        $dataAssignees = $data['assignees'] ?? null;
        $assignees = [];
        if (\is_array($dataAssignees)) {
            foreach ($dataAssignees as $assignee) {
                $userName = \is_array($assignee) ? ($assignee['username'] ?? null) : null;
                if (\is_string($userName)) {
                    $assignees[] = $userName;
                }
            }
        }

        $dataReviewers = $data['reviewers'] ?? null;
        $reviewers = [];
        if (\is_array($dataReviewers)) {
            foreach ($dataReviewers as $reviewer) {
                $userName = \is_array($reviewer) ? ($reviewer['username'] ?? null) : null;
                if (\is_string($userName)) {
                    $reviewers[] = $userName;
                }
            }
        }

        $isDraft = (bool) ($data['draft'] ?? $data['work_in_progress'] ?? false);

        return new self(
            gitLabId: \is_int($gitLabId) ? $gitLabId : 0,
            gitLabIid: \is_int($gitLabIid) ? $gitLabIid : 0,
            gitLabProjectId: \is_int($gitLabProjectId) ? $gitLabProjectId : 0,
            gitLabUrl: \is_string($gitLabUrl) ? $gitLabUrl : '',
            title: $cleanedTitle,
            description: \is_string($description) ? $description : '',
            sourceBranch: \is_string($sourceBranch) ? $sourceBranch : '',
            targetBranch: \is_string($targetBranch) ? $targetBranch : 'main',
            state: $state,
            createdAt: \is_string($createdAt) ? (strtotime($createdAt) ?: 0) : 0,
            mergedAt: \is_string($mergedAt) ? (strtotime($mergedAt) ?: 0) : 0,
            commitSha: \is_string($commitSha) ? $commitSha : '',
            mergeCommitSha: \is_string($mergeCommitSha) ? $mergeCommitSha : '',
            baseSha: \is_string($baseSha) ? $baseSha : '',
            originalTitle: $title,
            labels: $labels,
            assignees: $assignees,
            reviewers: $reviewers,
            isDraft: $isDraft,
        );
    }

    /**
     * Sort MRs in GitLab merge order: merged MRs first (ascending by mergedAt),
     * then non-merged MRs sorted by iid. Critical because iid order ≠ merge order:
     * importing an iid out of merge order makes a later import pull commits that
     * shouldn't be on main yet, then subsequent MRs fail with "No commits between".
     */
    public static function compareByMergeOrder(self $a, self $b): int
    {
        if ($a->mergedAt && $b->mergedAt) {
            return $a->mergedAt <=> $b->mergedAt;
        }

        if (0 !== $a->mergedAt) {
            return -1;
        }

        if (0 !== $b->mergedAt) {
            return 1;
        }

        return $a->gitLabIid <=> $b->gitLabIid;
    }
}
