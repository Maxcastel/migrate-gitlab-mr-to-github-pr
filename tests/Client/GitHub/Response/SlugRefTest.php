<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\SlugRef;
use PHPUnit\Framework\TestCase;

final class SlugRefTest extends TestCase
{
    public function testKeepsOnlyTheSlugOfEachEntityInOrder(): void
    {
        $teams = SlugRef::buildListFromApiResponse([
            ['slug' => 'core', 'id' => 1, 'name' => 'Core team'],
            ['slug' => 'release', 'id' => 2, 'name' => 'Release team'],
        ]);

        self::assertSame(['core', 'release'], array_map(
            static fn (SlugRef $team): string => $team->slug,
            $teams
        ));
    }

    public function testDropsEntitiesItCannotName(): void
    {
        $teams = SlugRef::buildListFromApiResponse([
            ['slug' => 'core'],
            ['name' => 'Nameless'],
            ['slug' => 42],
            ['slug' => 'admins'],
        ]);

        self::assertSame(['core', 'admins'], array_map(
            static fn (SlugRef $team): string => $team->slug,
            $teams
        ));
    }

    public function testYieldsNoEntityWhenTheListIsAbsent(): void
    {
        self::assertSame([], SlugRef::buildListFromApiResponse(null));
    }

    public function testYieldsNoEntityWhenTheListIsNotEvenAList(): void
    {
        self::assertSame([], SlugRef::buildListFromApiResponse('core'));
    }
}
