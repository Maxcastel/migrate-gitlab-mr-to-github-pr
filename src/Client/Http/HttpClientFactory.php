<?php

declare(strict_types=1);

namespace App\Client\Http;

use GuzzleHttp\Client as GuzzleClient;

class HttpClientFactory
{
    public function create(bool $skipSslCertificateVerification): GuzzleClient
    {
        return new GuzzleClient(['verify' => !$skipSslCertificateVerification]);
    }
}
