<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\LoginRef;
use App\Client\GitHub\Response\PushRestrictions;
use App\Client\GitHub\Response\SlugRef;
use PHPUnit\Framework\TestCase;

final class PushRestrictionsTest extends TestCase
{
    public function testReadsTheUsersTeamsAndAppsAllowedToPush(): void
    {
        $restrictions = PushRestrictions::buildFromApiResponse([
            'url' => 'https://api.github.com/…',
            'users_url' => 'https://api.github.com/…',
            'users' => [['login' => 'carol']],
            'teams' => [['slug' => 'admins'], ['slug' => 'core']],
            'apps' => [['slug' => 'ci-app']],
        ]);

        self::assertSame(['carol'], array_map(static fn (LoginRef $u): string => $u->login, $restrictions->users));
        self::assertSame(['admins', 'core'], array_map(static fn (SlugRef $t): string => $t->slug, $restrictions->teams));
        self::assertSame(['ci-app'], array_map(static fn (SlugRef $a): string => $a->slug, $restrictions->apps));
    }

    public function testYieldsThreeEmptyListsWhenTheRestrictionNamesNobody(): void
    {
        $restrictions = PushRestrictions::buildFromApiResponse(['url' => 'https://api.github.com/…']);

        self::assertSame([], $restrictions->users);
        self::assertSame([], $restrictions->teams);
        self::assertSame([], $restrictions->apps);
    }
}
