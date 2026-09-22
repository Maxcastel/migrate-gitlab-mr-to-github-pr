<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\LoginRef;
use PHPUnit\Framework\TestCase;

final class LoginRefTest extends TestCase
{
    public function testKeepsOnlyTheLoginOfEachUserInOrder(): void
    {
        $users = LoginRef::buildListFromApiResponse([
            ['login' => 'alice', 'id' => 1, 'type' => 'User'],
            ['login' => 'bob', 'id' => 2, 'type' => 'User'],
        ]);

        self::assertSame(['alice', 'bob'], array_map(
            static fn (LoginRef $user): string => $user->login,
            $users
        ));
    }

    public function testDropsUsersItCannotName(): void
    {
        $users = LoginRef::buildListFromApiResponse([
            ['login' => 'alice'],
            ['id' => 2],
            ['login' => 42],
            ['login' => 'carol'],
        ]);

        self::assertSame(['alice', 'carol'], array_map(
            static fn (LoginRef $user): string => $user->login,
            $users
        ));
    }

    public function testYieldsNoUserWhenTheListIsAbsent(): void
    {
        self::assertSame([], LoginRef::buildListFromApiResponse(null));
    }

    public function testYieldsNoUserWhenTheListIsNotEvenAList(): void
    {
        self::assertSame([], LoginRef::buildListFromApiResponse('alice'));
    }
}
