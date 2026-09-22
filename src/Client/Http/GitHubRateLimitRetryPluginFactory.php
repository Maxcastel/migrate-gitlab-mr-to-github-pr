<?php

declare(strict_types=1);

namespace App\Client\Http;

use Github\Exception\ApiLimitExceedException;
use Http\Client\Common\Plugin\RetryPlugin;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

class GitHubRateLimitRetryPluginFactory
{
    private const MAX_RETRIES = 5;

    private const MAX_WAIT_SECONDS = 60;

    public function create(): RetryPlugin
    {
        return new RetryPlugin([
            'retries' => self::MAX_RETRIES,
            'exception_decider' => static fn (RequestInterface $request, ClientExceptionInterface $e): bool => $e instanceof ApiLimitExceedException,
            'exception_delay' => static function (RequestInterface $request, ClientExceptionInterface $e, int $retries): int {
                $wait = self::MAX_WAIT_SECONDS;
                if ($e instanceof ApiLimitExceedException) {
                    $secondsUntilReset = $e->getResetTime() - time();
                    $wait = max(1, min($secondsUntilReset, self::MAX_WAIT_SECONDS));
                }

                return $wait * 1_000_000;
            },
        ]);
    }
}
