<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\DismissalRestrictions;
use App\Client\GitHub\Response\LoginRef;
use App\Client\GitHub\Response\SlugRef;
use PHPUnit\Framework\TestCase;

final class DismissalRestrictionsTest extends TestCase
{
    public function testReadsTheUsersTeamsAndAppsAllowedToDismiss(): void
    {
        $restrictions = DismissalRestrictions::buildFromApiResponse([
            'url' => 'https://api.github.com/…',
            'users' => [['login' => 'alice'], ['login' => 'bob']],
            'teams' => [['slug' => 'core']],
            'apps' => [['slug' => 'dismiss-app']],
        ]);

        self::assertSame(['alice', 'bob'], array_map(static fn (LoginRef $u): string => $u->login, $restrictions->users));
        self::assertSame(['core'], array_map(static fn (SlugRef $t): string => $t->slug, $restrictions->teams));
        self::assertSame(['dismiss-app'], array_map(static fn (SlugRef $a): string => $a->slug, $restrictions->apps));
    }

    public function testYieldsThreeEmptyListsWhenTheRestrictionNamesNobody(): void
    {
        $restrictions = DismissalRestrictions::buildFromApiResponse(['url' => 'https://api.github.com/…']);

        self::assertSame([], $restrictions->users);
        self::assertSame([], $restrictions->teams);
        self::assertSame([], $restrictions->apps);
    }
}
