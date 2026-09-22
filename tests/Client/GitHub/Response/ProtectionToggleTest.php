<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\ProtectionToggle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtectionToggleTest extends TestCase
{
    public function testReadsAnEnforcementThatIsOn(): void
    {
        $toggle = ProtectionToggle::buildFromApiResponse(['url' => 'https://api.github.com/…', 'enabled' => true]);

        self::assertNotNull($toggle);
        self::assertTrue($toggle->enabled);
    }

    public function testReadsAnEnforcementThatIsOffWithoutCollapsingItToAbsent(): void
    {
        $toggle = ProtectionToggle::buildFromApiResponse(['enabled' => false]);

        self::assertNotNull($toggle);
        self::assertFalse($toggle->enabled);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadableTogglePayloadProvider(): iterable
    {
        yield 'section omitted by GitHub' => [null];
        yield 'section is not an object' => ['enabled'];
        yield 'object without enabled' => [['url' => 'https://api.github.com/…']];
        yield 'enabled reported as a string' => [['enabled' => 'true']];
    }

    #[DataProvider('unreadableTogglePayloadProvider')]
    public function testYieldsNothingWhenTheEnforcementCannotBeRead(mixed $payload): void
    {
        self::assertNull(ProtectionToggle::buildFromApiResponse($payload));
    }
}
