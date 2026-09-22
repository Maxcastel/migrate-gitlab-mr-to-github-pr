<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\LoginRef;
use App\Client\GitHub\Response\RequiredPullRequestReviews;
use PHPUnit\Framework\TestCase;

final class RequiredPullRequestReviewsTest extends TestCase
{
    public function testReadsTheWholeReviewPolicy(): void
    {
        $reviews = RequiredPullRequestReviews::buildFromApiResponse([
            'url' => 'https://api.github.com/…',
            'dismiss_stale_reviews' => true,
            'require_code_owner_reviews' => true,
            'required_approving_review_count' => 2,
            'require_last_push_approval' => true,
            'dismissal_restrictions' => ['users' => [['login' => 'alice']]],
        ]);

        self::assertTrue($reviews->dismissStaleReviews);
        self::assertTrue($reviews->requireCodeOwnerReviews);
        self::assertSame(2, $reviews->requiredApprovingReviewCount);
        self::assertTrue($reviews->requireLastPushApproval);
        self::assertNotNull($reviews->dismissalRestrictions);
        self::assertSame(['alice'], array_map(
            static fn (LoginRef $u): string => $u->login,
            $reviews->dismissalRestrictions->users
        ));
    }

    public function testKeepsEveryDisabledFlagDistinctFromAnAbsentOne(): void
    {
        $reviews = RequiredPullRequestReviews::buildFromApiResponse([
            'dismiss_stale_reviews' => false,
            'require_code_owner_reviews' => false,
            'required_approving_review_count' => 0,
        ]);

        self::assertFalse($reviews->dismissStaleReviews);
        self::assertFalse($reviews->requireCodeOwnerReviews);
        self::assertSame(0, $reviews->requiredApprovingReviewCount);
        self::assertNull($reviews->requireLastPushApproval, 'required_approving_review_count never reported: stays null, does not become false');
    }

    public function testIgnoresFieldsItCannotRead(): void
    {
        $reviews = RequiredPullRequestReviews::buildFromApiResponse([
            'dismiss_stale_reviews' => 'true',
            'required_approving_review_count' => '2',
            'require_last_push_approval' => 1,
        ]);

        self::assertNull($reviews->dismissStaleReviews);
        self::assertNull($reviews->requiredApprovingReviewCount);
        self::assertNull($reviews->requireLastPushApproval);
    }

    public function testYieldsNoDismissalRestrictionsWhenTheSectionIsAbsentOrEmpty(): void
    {
        self::assertNull(RequiredPullRequestReviews::buildFromApiResponse([])->dismissalRestrictions);
        self::assertNull(RequiredPullRequestReviews::buildFromApiResponse(['dismissal_restrictions' => []])->dismissalRestrictions);
        self::assertNull(RequiredPullRequestReviews::buildFromApiResponse(['dismissal_restrictions' => 'alice'])->dismissalRestrictions);
    }

    public function testIgnoresTheBypassAllowancesItNeverRestores(): void
    {
        $reviews = RequiredPullRequestReviews::buildFromApiResponse([
            'bypass_pull_request_allowances' => ['users' => [['login' => 'alice']]],
        ]);

        self::assertNull($reviews->dismissStaleReviews);
        self::assertNull($reviews->dismissalRestrictions);
    }
}
