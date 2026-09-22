<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\BranchProtection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BranchProtectionTest extends TestCase
{
    public function testReadsEveryEnforcedSection(): void
    {
        $protection = BranchProtection::buildFromApiResponse([
            'required_status_checks' => ['strict' => true, 'contexts' => ['ci/build']],
            'required_pull_request_reviews' => ['required_approving_review_count' => 2],
            'restrictions' => ['users' => [['login' => 'carol']]],
            'enforce_admins' => ['enabled' => true],
            'required_linear_history' => ['enabled' => true],
            'allow_force_pushes' => ['enabled' => true],
            'allow_deletions' => ['enabled' => true],
            'block_creations' => ['enabled' => true],
            'required_conversation_resolution' => ['enabled' => true],
            'lock_branch' => ['enabled' => true],
            'allow_fork_syncing' => ['enabled' => true],
        ]);

        self::assertTrue($protection->requiredStatusChecks?->strict);
        self::assertSame(2, $protection->requiredPullRequestReviews?->requiredApprovingReviewCount);
        self::assertCount(1, $protection->restrictions->users ?? []);
        self::assertTrue($protection->enforceAdmins?->enabled);
        self::assertTrue($protection->requiredLinearHistory?->enabled);
        self::assertTrue($protection->allowForcePushes?->enabled);
        self::assertTrue($protection->allowDeletions?->enabled);
        self::assertTrue($protection->blockCreations?->enabled);
        self::assertTrue($protection->requiredConversationResolution?->enabled);
        self::assertTrue($protection->lockBranch?->enabled);
        self::assertTrue($protection->allowForkSyncing?->enabled);
    }

    public function testReadsAnEnforcementThatIsExplicitlyOff(): void
    {
        $protection = BranchProtection::buildFromApiResponse(['enforce_admins' => ['enabled' => false]]);

        self::assertNotNull($protection->enforceAdmins);
        self::assertFalse($protection->enforceAdmins->enabled);
    }

    public function testYieldsNothingEnforcedForAnEmptyResponse(): void
    {
        $protection = BranchProtection::buildFromApiResponse([]);

        self::assertNull($protection->requiredStatusChecks);
        self::assertNull($protection->requiredPullRequestReviews);
        self::assertNull($protection->restrictions);
        self::assertNull($protection->enforceAdmins);
        self::assertNull($protection->allowForkSyncing);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function emptySectionProvider(): iterable
    {
        yield 'status checks reported as an empty object' => [['required_status_checks' => []]];
        yield 'reviews reported as an empty object' => [['required_pull_request_reviews' => []]];
        yield 'restrictions reported as an empty object' => [['restrictions' => []]];
        yield 'status checks reported as a string' => [['required_status_checks' => 'strict']];
        yield 'reviews reported as a string' => [['required_pull_request_reviews' => 'all']];
        yield 'restrictions reported as a string' => [['restrictions' => 'nobody']];
    }

    /**
     * @param array<mixed> $payload
     */
    #[DataProvider('emptySectionProvider')]
    public function testDropsASectionThatEnforcesNothing(array $payload): void
    {
        $protection = BranchProtection::buildFromApiResponse($payload);

        self::assertNull($protection->requiredStatusChecks);
        self::assertNull($protection->requiredPullRequestReviews);
        self::assertNull($protection->restrictions);
    }

    public function testIgnoresTheFieldsThatDescribeTheResourceRatherThanThePolicy(): void
    {
        $protection = BranchProtection::buildFromApiResponse([
            'url' => 'https://api.github.com/…',
            'name' => 'main',
            'protection_url' => 'https://api.github.com/…',
            'enabled' => true,
            'required_signatures' => ['enabled' => true],
        ]);

        self::assertEquals(new BranchProtection(), $protection);
    }
}
