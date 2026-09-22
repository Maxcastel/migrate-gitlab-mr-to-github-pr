<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

/**
 * @see https://docs.github.com/rest/branches/branch-protection#get-branch-protection
 */
final readonly class RequiredPullRequestReviews
{
    public function __construct(
        public ?bool $dismissStaleReviews = null,
        public ?bool $requireCodeOwnerReviews = null,
        public ?int $requiredApprovingReviewCount = null,
        public ?bool $requireLastPushApproval = null,
        public ?DismissalRestrictions $dismissalRestrictions = null,
    ) {}

    /**
     * @param array<mixed> $payload
     */
    public static function buildFromApiResponse(array $payload): self
    {
        $dismissStaleReviews = $payload['dismiss_stale_reviews'] ?? null;
        $requireCodeOwnerReviews = $payload['require_code_owner_reviews'] ?? null;
        $requiredApprovingReviewCount = $payload['required_approving_review_count'] ?? null;
        $requireLastPushApproval = $payload['require_last_push_approval'] ?? null;
        $dismissalRestrictions = $payload['dismissal_restrictions'] ?? null;

        return new self(
            \is_bool($dismissStaleReviews) ? $dismissStaleReviews : null,
            \is_bool($requireCodeOwnerReviews) ? $requireCodeOwnerReviews : null,
            \is_int($requiredApprovingReviewCount) ? $requiredApprovingReviewCount : null,
            \is_bool($requireLastPushApproval) ? $requireLastPushApproval : null,
            \is_array($dismissalRestrictions) && [] !== $dismissalRestrictions
                ? DismissalRestrictions::buildFromApiResponse($dismissalRestrictions)
                : null,
        );
    }
}
