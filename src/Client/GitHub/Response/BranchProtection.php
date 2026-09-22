<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

/**
 * @see https://docs.github.com/rest/branches/branch-protection#get-branch-protection
 */
final readonly class BranchProtection
{
    public function __construct(
        public ?RequiredStatusChecks $requiredStatusChecks = null,
        public ?RequiredPullRequestReviews $requiredPullRequestReviews = null,
        public ?PushRestrictions $restrictions = null,
        public ?ProtectionToggle $enforceAdmins = null,
        public ?ProtectionToggle $requiredLinearHistory = null,
        public ?ProtectionToggle $allowForcePushes = null,
        public ?ProtectionToggle $allowDeletions = null,
        public ?ProtectionToggle $blockCreations = null,
        public ?ProtectionToggle $requiredConversationResolution = null,
        public ?ProtectionToggle $lockBranch = null,
        public ?ProtectionToggle $allowForkSyncing = null,
    ) {}

    /**
     * @param array<mixed> $payload
     */
    public static function buildFromApiResponse(array $payload): self
    {
        $statusChecks = $payload['required_status_checks'] ?? null;
        $reviews = $payload['required_pull_request_reviews'] ?? null;
        $restrictions = $payload['restrictions'] ?? null;

        return new self(
            \is_array($statusChecks) && [] !== $statusChecks ? RequiredStatusChecks::buildFromApiResponse($statusChecks) : null,
            \is_array($reviews) && [] !== $reviews ? RequiredPullRequestReviews::buildFromApiResponse($reviews) : null,
            \is_array($restrictions) && [] !== $restrictions ? PushRestrictions::buildFromApiResponse($restrictions) : null,
            ProtectionToggle::buildFromApiResponse($payload['enforce_admins'] ?? null),
            ProtectionToggle::buildFromApiResponse($payload['required_linear_history'] ?? null),
            ProtectionToggle::buildFromApiResponse($payload['allow_force_pushes'] ?? null),
            ProtectionToggle::buildFromApiResponse($payload['allow_deletions'] ?? null),
            ProtectionToggle::buildFromApiResponse($payload['block_creations'] ?? null),
            ProtectionToggle::buildFromApiResponse($payload['required_conversation_resolution'] ?? null),
            ProtectionToggle::buildFromApiResponse($payload['lock_branch'] ?? null),
            ProtectionToggle::buildFromApiResponse($payload['allow_fork_syncing'] ?? null),
        );
    }
}
