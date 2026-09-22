<?php

declare(strict_types=1);

namespace App\Service;

use App\Client\GitHub\GitHubApiClient;
use App\Client\GitHub\GitHubAttachmentApiClient;
use App\Client\GitHub\Response\BranchProtection;
use App\Client\GitHub\Response\CreatedCommit;
use App\Client\GitHub\Response\CreatedPullRequest;
use App\Client\GitHub\Response\DismissalRestrictions;
use App\Client\GitHub\Response\LoginRef;
use App\Client\GitHub\Response\PushRestrictions;
use App\Client\GitHub\Response\RepoCommit;
use App\Client\GitHub\Response\RequiredPullRequestReviews;
use App\Client\GitHub\Response\RequiredStatusChecks;
use App\Client\GitHub\Response\SlugRef;
use App\Entity\Issue;
use App\Entity\IssueState;
use App\Entity\MergeRequest;
use App\Entity\MergeRequestState;
use App\Exception\Api\GitHubApiException;
use App\Exception\ImportException;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LogicException;

class GitHubService
{
    private GitHubApiClient $api;

    /** @var array<int, string> */
    private array $currentIssueTitles = [];

    /** @var array<int, string> */
    private array $currentPullRequestTitles = [];

    /** @var array<int, string> */
    private array $currentPullRequestBranches = [];

    /** @var array<string, string> */
    public array $mergeFailureMessages = [];

    /** @var array<string, string> */
    public array $reviewRequestWarnings = [];

    private string $lastMergeShaForMain = '';

    private string $lastReplayedGitLabMainSha = '';

    private int $repositoryId = 0;

    /**
     * @var array<string, string>
     */
    private array $rebuiltCommits = [];

    /**
     * Header templates support 3 placeholders: {date}, {iid}, {url}.
     * Empty template ('') = no header (only description)".
     */
    public function __construct(
        GitHubApiClient $api = new GitHubApiClient(),
        private string $issueBodyHeader = "**Created on GitLab:** {date}\n**GitLab Issue:** [#{iid}]({url})\n\n",
        private string $mergeRequestBodyHeader = "**Created on GitLab:** {date}\n**GitLab MR:** [!{iid}]({url})\n\n",
        private string $bodyDateTimezone = 'Europe/Paris',
        private string $bodyDateFormat = 'd/m/Y H:i:s',
        private string $assignee = '',
        private GitHubAttachmentApiClient $attachmentApi = new GitHubAttachmentApiClient(),
    ) {
        $this->api = $api;
    }

    /**
     * @throws ImportException
     */
    public function init(
        string $token,
        string $userName,
        string $repositoryName,
        bool $skipSslCertificateVerification = false,
    ): void {
        try {
            $this->api->connect($token, $userName, $repositoryName, $skipSslCertificateVerification);
            $this->attachmentApi->connect($token, $skipSslCertificateVerification);
        } catch (GitHubApiException $gitHubApiException) {
            throw new ImportException('Error authenticating at GitHub: '.$gitHubApiException->getMessage(), $gitHubApiException->getCode(), $gitHubApiException);
        }

        $this->loadCurrentIssues();
        $this->loadCurrentPullRequests();
    }

    /**
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    private function formatBodyDate(?int $timestamp): string
    {
        if (!$timestamp) {
            return 'Unknown';
        }

        $dt = (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone($this->bodyDateTimezone));

        return $dt->format($this->bodyDateFormat);
    }

    private function buildBody(string $template, string $date, int $iid, string $url, ?string $description): string
    {
        $header = strtr($template, [
            '{date}' => $date,
            '{iid}' => $iid,
            '{url}' => $url,
            '\n' => "\n",
        ]);

        return $header.($description ?? '');
    }

    /**
     * Falls back to the authenticated user if assignee is not set.
     *
     * @throws LogicException
     */
    private function resolveAssignee(): string
    {
        return $this->assignee ?: $this->api->getUserName();
    }

    /**
     * @throws ImportException
     */
    private function loadCurrentIssues(): void
    {
        try {
            $issues = $this->api->fetchAllIssues();
        } catch (GitHubApiException $gitHubApiException) {
            throw new ImportException('Error retrieving issues from GitHub: '.$gitHubApiException->getMessage(), $gitHubApiException->getCode(), $gitHubApiException);
        }

        foreach ($issues as $issue) {
            $this->currentIssueTitles[$issue->number] = $issue->title;
        }
    }

    /**
     * @throws ImportException
     */
    private function loadCurrentPullRequests(): void
    {
        try {
            $prs = $this->api->fetchAllPullRequests();
        } catch (GitHubApiException $gitHubApiException) {
            throw new ImportException('Error retrieving pull requests from GitHub: '.$gitHubApiException->getMessage(), $gitHubApiException->getCode(), $gitHubApiException);
        }

        foreach ($prs as $pr) {
            $this->currentPullRequestTitles[$pr->number] = $pr->title;
            if (null !== $pr->head) {
                $this->currentPullRequestBranches[$pr->number] = $pr->head->ref;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function getCurrentIssueTitles(): array
    {
        return $this->currentIssueTitles;
    }

    /**
     * @return array<int, string>
     */
    public function getCurrentPullRequestTitles(): array
    {
        return $this->currentPullRequestTitles;
    }

    public function isImported(Issue $issue): bool
    {
        return \in_array($issue->title, $this->currentIssueTitles, true);
    }

    public function isPullRequestImported(MergeRequest $mergeRequest): bool
    {
        return \in_array($mergeRequest->sourceBranch, $this->currentPullRequestBranches, true);
    }

    /**
     * @throws ImportException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws LogicException
     */
    public function importIssue(Issue $issue): void
    {
        $params = [
            'title' => $issue->title,
            'body' => $this->buildBody(
                $this->issueBodyHeader,
                $this->formatBodyDate($issue->createdAt),
                $issue->gitLabIid,
                $issue->gitLabUrl,
                $issue->description,
            ),
        ];

        if ([] !== $issue->assignees) {
            $params['assignees'] = [$this->resolveAssignee()];
        }

        try {
            $result = $this->api->createIssue($params);
        } catch (GitHubApiException $gitHubApiException) {
            throw new ImportException('Error adding issue to GitHub: '.$gitHubApiException->getMessage(), $gitHubApiException->getCode(), $gitHubApiException);
        }

        if (IssueState::Closed === $issue->state) {
            $this->closeIssue($result->number);
        }
    }

    /**
     * @throws ImportException
     */
    private function closeIssue(int $issueNumber): void
    {
        try {
            $this->api->closeIssue($issueNumber);
        } catch (GitHubApiException $gitHubApiException) {
            throw new ImportException(\sprintf('Error closing issue #%d on GitHub: %s', $issueNumber, $gitHubApiException->getMessage()), $gitHubApiException->getCode(), $gitHubApiException);
        }
    }

    /**
     * Attaches a file to the repository the way the web interface does when a file is dropped
     * into an issue.
     *
     * @throws ImportException
     * @throws LogicException
     */
    public function uploadAttachment(string $fileName, string $contentType, string $contents): string
    {
        try {
            if (0 === $this->repositoryId) {
                $this->repositoryId = $this->api->getRepositoryId();
            }

            return $this->attachmentApi->upload($this->repositoryId, $fileName, $contentType, $contents);
        } catch (GitHubApiException $gitHubApiException) {
            throw new ImportException($gitHubApiException->getMessage(), $gitHubApiException->getCode(), $gitHubApiException);
        }
    }

    /**
     * @throws ImportException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     * @throws LogicException
     */
    public function importMergeRequest(MergeRequest $mergeRequest): void
    {
        $branchToUse = $mergeRequest->sourceBranch;
        $targetBranch = $mergeRequest->targetBranch;
        $state = $mergeRequest->state;
        $mergeCommitSha = $mergeRequest->mergeCommitSha;
        $mrRef = '!'.$mergeRequest->gitLabIid;

        if ('' === $branchToUse || '' === $targetBranch) {
            throw new ImportException('Source or target branch is empty');
        }

        try {
            if (!$this->api->branchExists($branchToUse)) {
                if ('' === $mergeRequest->commitSha) {
                    throw new ImportException(\sprintf("Cannot create source branch '%s'", $branchToUse));
                }

                $this->api->createBranch($branchToUse, $mergeRequest->commitSha);
            }

            $userName = $this->api->getUserName();
            $params = [
                'title' => $mergeRequest->title,
                'body' => $this->buildBody(
                    $this->mergeRequestBodyHeader,
                    $this->formatBodyDate($mergeRequest->createdAt),
                    $mergeRequest->gitLabIid,
                    $mergeRequest->gitLabUrl,
                    $mergeRequest->description,
                ),
                'head' => $userName.':'.$branchToUse,
            ];
            if ($mergeRequest->isDraft) {
                $params['draft'] = true;
            }

            $result = $this->createPRWithPreMergeBase($mergeRequest, $branchToUse, $params);

            $prNumber = $result->number;
            $assignee = $this->resolveAssignee();

            if ([] !== $mergeRequest->assignees) {
                $this->api->updateIssueAssignees($prNumber, [$assignee]);
            }

            if ([] !== $mergeRequest->reviewers) {
                try {
                    $this->api->requestReviewers($prNumber, [$assignee]);
                } catch (GitHubApiException) {
                    $this->reviewRequestWarnings[$mrRef] = \sprintf(
                        'Failed to request review from %s for PR #%d because the reviewer is also the author. Skipping review request.',
                        $assignee,
                        $prNumber
                    );
                }
            }

            if (MergeRequestState::Merged === $state) {
                try {
                    $mergedCommit = $this->api->showCommit($mergeCommitSha);
                    $mainBranchInfo = $this->api->getBranch($targetBranch);
                    $mergeDateIso = (new DateTimeImmutable('@'.$mergeRequest->mergedAt))
                        ->format(DateTimeInterface::ATOM);

                    $authorInfo = [
                        'name' => $mergedCommit->author->name ?? $userName,
                        'email' => $mergedCommit->author->email ?? $userName.'@users.noreply.github.com',
                        'date' => $mergeDateIso,
                    ];

                    $newCommit = $this->api->createCommit([
                        'message' => \sprintf('Merge pull request #%s from %s/%s', $prNumber, $userName, $branchToUse),
                        'tree' => $mergedCommit->tree->sha,
                        'parents' => [$mainBranchInfo->commit->sha, $result->head->sha],
                        'author' => $authorInfo,
                        'committer' => $authorInfo,
                    ]);

                    $this->api->forceUpdateBranch($targetBranch, $newCommit->sha);

                    $this->lastMergeShaForMain = $newCommit->sha;

                    $this->lastReplayedGitLabMainSha = $mergeCommitSha;

                    $this->rebuiltCommits[$mergeCommitSha] = $newCommit->sha;
                } catch (GitHubApiException $mergeEx) {
                    $this->mergeFailureMessages[$mrRef] = $mergeEx->getMessage();
                    $this->api->closePullRequest($prNumber);
                }
            } elseif (MergeRequestState::Closed === $state) {
                $this->api->closePullRequest($prNumber);
            }
        } catch (GitHubApiException $gitHubApiException) {
            throw new ImportException(\sprintf('Error for MR %s (%s → %s): %s', $mrRef, $branchToUse, $targetBranch, $gitHubApiException->getMessage()), $gitHubApiException->getCode(), $gitHubApiException);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws GitHubApiException
     * @throws LogicException
     */
    private function createPRWithUniqueBranch(string $branchToUse, array $params): CreatedPullRequest
    {
        $branch = $this->api->getBranch($branchToUse);
        $this->api->createBranch($branchToUse, $branch->commit->sha);

        $params['head'] = $this->api->getUserName().':'.$branchToUse;

        return $this->api->createPullRequest($params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws GitHubApiException
     * @throws ImportException
     * @throws LogicException
     */
    private function createPRWithPreMergeBase(MergeRequest $mergeRequest, string $branchToUse, array $params): CreatedPullRequest
    {
        $baseBeforeMerge = '';
        $featureTip = null;
        $isMerged = '' !== $mergeCommitSha = $mergeRequest->mergeCommitSha;
        $targetBranch = $mergeRequest->targetBranch;
        $baseSha = $mergeRequest->baseSha;

        if ($isMerged) {
            if ([] === $mergeCommitParents = $this->api->showCommit($mergeCommitSha)->parents) {
                throw new ImportException('Merge commit has no parents');
            }

            $baseBeforeMerge = $mergeCommitParents[0]->sha;
            if (\count($mergeCommitParents) >= 2) {
                $featureTip = $mergeCommitParents[1]->sha;
            }
        } elseif ('' !== $baseSha) {
            $baseBeforeMerge = $baseSha;
        } else {
            throw new ImportException('No merge commit SHA and no diff_refs.base_sha - cannot find pre-merge base');
        }

        if (null !== $featureTip) {
            try {
                $this->api->forceUpdateBranch($branchToUse, $featureTip);
            } catch (GitHubApiException) {
                $this->api->createBranch($branchToUse, $featureTip);
            }
        }

        if ('' !== $this->lastReplayedGitLabMainSha && $baseBeforeMerge !== $this->lastReplayedGitLabMainSha) {
            $newTip = $this->cherryPickCommitsBetween(
                $this->lastReplayedGitLabMainSha,
                $baseBeforeMerge,
                $targetBranch
            );
            if ('' !== $newTip) {
                $this->lastMergeShaForMain = $newTip;
                $this->lastReplayedGitLabMainSha = $baseBeforeMerge;
            }
        }

        $mainBase = '' !== $this->lastMergeShaForMain
            ? $this->lastMergeShaForMain
            : $baseBeforeMerge;

        $this->rebuiltCommits[$baseBeforeMerge] = $mainBase;

        $this->rebaseFeatureBranchOnto($branchToUse, $baseBeforeMerge, $mainBase);

        $this->api->forceUpdateBranch($targetBranch, $mainBase);

        if (!$isMerged && '' === $this->lastMergeShaForMain) {
            $this->lastMergeShaForMain = $mainBase;
            $this->lastReplayedGitLabMainSha = $baseBeforeMerge;
        }

        $params['base'] = $targetBranch;

        try {
            $result = $this->api->createPullRequest($params);
        } catch (GitHubApiException $gitHubApiException) {
            if (str_contains($gitHubApiException->getMessage(), 'already exists')) {
                $result = $this->createPRWithUniqueBranch($branchToUse, $params);
            } else {
                throw $gitHubApiException;
            }
        } finally {
            try {
                $this->api->forceUpdateBranch($targetBranch, $mainBase);
            } catch (GitHubApiException) {
            }
        }

        return $result;
    }

    /**
     * After all MRs are imported, recreate any commits that sit on GitLab main
     * AFTER the last replayed one (eg. a final chore(release): vX.Y.Z bumped
     * once everything was merged).
     *
     * @throws GitHubApiException
     */
    public function cherryPickTrailingNonMrCommits(string $gitLabMainHeadSha, string $targetBranch): void
    {
        if ('' === $this->lastReplayedGitLabMainSha || $gitLabMainHeadSha === $this->lastReplayedGitLabMainSha) {
            return;
        }

        $newTip = $this->cherryPickCommitsBetween(
            $this->lastReplayedGitLabMainSha,
            $gitLabMainHeadSha,
            $targetBranch
        );

        if ('' !== $newTip) {
            $this->lastMergeShaForMain = $newTip;
        }
    }

    /**
     * Recreate every non-merge commit on GitLab main between $fromSha (exclusive)
     * and $toSha (inclusive), placing them on top of the current $targetBranch
     * on GitHub. Each commit's message/tree/author/committer is preserved so
     * dates and authorship match the GitLab original.
     *
     * Merge commits (parents >= 2) are skipped: they correspond to GitLab MR
     * merges and we replace those with our own "Merge pull request #N" commits.
     *
     * Returns the SHA of the last cherry-picked commit, or '' if nothing was
     * cherry-picked (so the caller knows whether to update lastMergeShaForMain).
     *
     * @throws GitHubApiException
     */
    private function cherryPickCommitsBetween(string $fromSha, string $toSha, string $targetBranch): string
    {
        $compare = $this->api->compareCommits($fromSha, $toSha);

        $currentTip = $this->lastMergeShaForMain;
        $cherryPicked = false;

        foreach ($compare->commits as $commit) {
            $parents = $commit->parents;
            if (null !== $parents && \count($parents) >= 2) {
                continue;
            }

            $currentTip = $this->recreateCommitOnParent($commit, $currentTip)->sha;
            $this->rebuiltCommits[$commit->sha] = $currentTip;
            $cherryPicked = true;
        }

        if (!$cherryPicked) {
            return '';
        }

        $this->api->forceUpdateBranch($targetBranch, $currentTip);

        return $currentTip;
    }

    /**
     * Rebase the GitHub branch $branchName so its own commits are recreated on top of the
     * rebuilt history, like `git rebase --onto <rebuilt divergence point> $oldBaseSha`.
     *
     * Each original commit's tree, message, author and committer are preserved;
     * only the parent chain (and therefore the SHA) changes. After the rebase,
     * $branchName points to the last rebased commit.
     *
     * @throws GitHubApiException
     */
    private function rebaseFeatureBranchOnto(string $branchName, string $oldBaseSha, string $newBaseSha): void
    {
        $compare = $this->api->compareCommits($oldBaseSha, $branchName);

        $divergenceSha = $compare->mergeBaseCommit?->sha;
        $base = null === $divergenceSha
            ? $newBaseSha
            : ($this->rebuiltCommits[$divergenceSha] ?? $divergenceSha);

        $previousSha = $base;
        foreach ($compare->commits as $commit) {
            $previousSha = $this->recreateCommitOnParent($commit, $previousSha)->sha;
        }

        if ($previousSha !== $base) {
            $this->api->forceUpdateBranch($branchName, $previousSha);
        }
    }

    /**
     * @throws GitHubApiException
     */
    private function recreateCommitOnParent(RepoCommit $commit, string $parentSha): CreatedCommit
    {
        $commitDetail = $commit->commit;

        $params = [
            'message' => $commitDetail->message,
            'tree' => $commitDetail->tree->sha,
            'parents' => [$parentSha],
        ];

        if (null !== $author = $commitDetail->author) {
            $params['author'] = $author;
        }

        if (null !== $committer = $commitDetail->committer) {
            $params['committer'] = $committer;
        }

        return $this->api->createCommit($params);
    }

    /**
     * @throws GitHubApiException
     */
    public function restoreBranchProtection(string $branch, BranchProtection $savedProtection): void
    {
        $this->api->updateBranchProtection(
            $branch,
            $this->buildProtectionUpdatePayload($savedProtection)
        );
    }

    /**
     * @throws GitHubApiException
     */
    public function protectBranchWithDefaults(string $branch): void
    {
        $this->api->updateBranchProtection($branch, [
            'required_status_checks' => null,
            'enforce_admins' => false,
            'required_pull_request_reviews' => null,
            'restrictions' => null,
            'allow_force_pushes' => false,
            'allow_deletions' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProtectionUpdatePayload(BranchProtection $p): array
    {
        $savedStatusChecks = $p->requiredStatusChecks;
        $requiredStatusChecks = null;
        if ($savedStatusChecks instanceof RequiredStatusChecks) {
            $requiredStatusChecks = [
                'strict' => $savedStatusChecks->strict ?? false,
                'contexts' => $savedStatusChecks->contexts ?? [],
            ];
        }

        $requiredPullRequestReviews = $p->requiredPullRequestReviews;
        $requiredPrReviews = null;
        if ($requiredPullRequestReviews instanceof RequiredPullRequestReviews) {
            $requiredPrReviews = [
                'dismiss_stale_reviews' => $requiredPullRequestReviews->dismissStaleReviews ?? false,
                'require_code_owner_reviews' => $requiredPullRequestReviews->requireCodeOwnerReviews ?? false,
                'required_approving_review_count' => $requiredPullRequestReviews->requiredApprovingReviewCount ?? 0,
            ];

            if (null !== $requireLastPushApproval = $requiredPullRequestReviews->requireLastPushApproval) {
                $requiredPrReviews['require_last_push_approval'] = $requireLastPushApproval;
            }

            $dismissalRestrictions = $requiredPullRequestReviews->dismissalRestrictions;
            if ($dismissalRestrictions instanceof DismissalRestrictions) {
                $requiredPrReviews['dismissal_restrictions'] = [
                    'users' => array_map(static fn (LoginRef $u): string => $u->login, $dismissalRestrictions->users),
                    'teams' => array_map(static fn (SlugRef $t): string => $t->slug, $dismissalRestrictions->teams),
                    'apps' => array_map(static fn (SlugRef $a): string => $a->slug, $dismissalRestrictions->apps),
                ];
            }
        }

        $pushRestrictions = $p->restrictions;
        $restrictions = null;
        if ($pushRestrictions instanceof PushRestrictions) {
            $restrictions = [
                'users' => array_map(static fn (LoginRef $u): string => $u->login, $pushRestrictions->users),
                'teams' => array_map(static fn (SlugRef $t): string => $t->slug, $pushRestrictions->teams),
                'apps' => array_map(static fn (SlugRef $a): string => $a->slug, $pushRestrictions->apps),
            ];
        }

        return [
            'required_status_checks' => $requiredStatusChecks,
            'enforce_admins' => $p->enforceAdmins->enabled ?? false,
            'required_pull_request_reviews' => $requiredPrReviews,
            'restrictions' => $restrictions,
            'required_linear_history' => $p->requiredLinearHistory->enabled ?? false,
            'allow_force_pushes' => $p->allowForcePushes->enabled ?? false,
            'allow_deletions' => $p->allowDeletions->enabled ?? false,
            'block_creations' => $p->blockCreations->enabled ?? false,
            'required_conversation_resolution' => $p->requiredConversationResolution->enabled ?? false,
            'lock_branch' => $p->lockBranch->enabled ?? false,
            'allow_fork_syncing' => $p->allowForkSyncing->enabled ?? false,
        ];
    }
}
