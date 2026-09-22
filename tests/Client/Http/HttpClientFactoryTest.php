<?php

declare(strict_types=1);

namespace App\Tests\Client\Http;

use App\Client\Http\HttpClientFactory;
use PHPUnit\Framework\TestCase;

final class HttpClientFactoryTest extends TestCase
{
    public function testVerifiesCertificatesByDefault(): void
    {
        self::assertTrue(
            (new HttpClientFactory())->create(false)->getConfig('verify'),
            'verify must be true when skipSslCertificateVerification=false'
        );
    }

    public function testDisablesCertificateVerificationWhenAsked(): void
    {
        self::assertFalse(
            (new HttpClientFactory())->create(true)->getConfig('verify'),
            'verify must be false when skipSslCertificateVerification=true'
        );
    }
}
