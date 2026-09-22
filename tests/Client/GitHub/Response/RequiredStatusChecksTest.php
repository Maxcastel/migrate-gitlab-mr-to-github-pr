<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\RequiredStatusChecks;
use PHPUnit\Framework\TestCase;

final class RequiredStatusChecksTest extends TestCase
{
    public function testReadsStrictAndTheContextList(): void
    {
        $checks = RequiredStatusChecks::buildFromApiResponse([
            'strict' => true,
            'contexts' => ['ci/build', 'ci/test'],
            'url' => 'https://api.github.com/…',
        ]);

        self::assertTrue($checks->strict);
        self::assertSame(['ci/build', 'ci/test'], $checks->contexts);
    }

    public function testKeepsStrictFalseDistinctFromStrictAbsent(): void
    {
        self::assertFalse(RequiredStatusChecks::buildFromApiResponse(['strict' => false])->strict);
        self::assertNull(RequiredStatusChecks::buildFromApiResponse(['contexts' => []])->strict);
    }

    public function testIgnoresAStrictItCannotRead(): void
    {
        self::assertNull(RequiredStatusChecks::buildFromApiResponse(['strict' => 'yes'])->strict);
    }

    public function testDropsContextsThatAreNotStrings(): void
    {
        $checks = RequiredStatusChecks::buildFromApiResponse(['contexts' => ['ci/build', 42, null, 'ci/test']]);

        self::assertSame(['ci/build', 'ci/test'], $checks->contexts);
    }

    public function testKeepsAnEmptyContextListDistinctFromAnAbsentOne(): void
    {
        self::assertSame([], RequiredStatusChecks::buildFromApiResponse(['contexts' => []])->contexts);
        self::assertNull(RequiredStatusChecks::buildFromApiResponse(['strict' => true])->contexts);
        self::assertNull(RequiredStatusChecks::buildFromApiResponse(['contexts' => 'ci/build'])->contexts);
    }

    public function testIgnoresTheChecksListTheUpdateEndpointDoesNotNeed(): void
    {
        $checks = RequiredStatusChecks::buildFromApiResponse([
            'checks' => [['context' => 'ci/build', 'app_id' => 7]],
        ]);

        self::assertNull($checks->contexts);
        self::assertNull($checks->strict);
    }
}
