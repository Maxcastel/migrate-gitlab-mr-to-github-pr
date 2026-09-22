<?php

declare(strict_types=1);

namespace App\Tests\Client\Http;

use App\Client\Http\GitHubRateLimitRetryPluginFactory;
use App\Tests\Helper\ServiceMockHelper;
use Exception;
use Github\Exception\ApiLimitExceedException;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use ReflectionException;
use RuntimeException;

final class GitHubRateLimitRetryPluginFactoryTest extends TestCase
{
    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testPluginIsConfiguredWithTheMaxRetryCount(): void
    {
        self::assertSame(5, $this->pluginProperty('retry'), 'the plugin must be configured with the max retry count');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testOnlyRateLimitExceptionsAreRetried(): void
    {
        $decider = $this->pluginCallback('exceptionDecider');
        $request = new Request('GET', 'test');
        $other = new class('boom') extends RuntimeException implements ClientExceptionInterface {};

        self::assertTrue($decider($request, $this->rateLimitException(10)), 'a rate-limit exception must be retried');
        self::assertFalse($decider($request, $other), 'any other client exception must NOT be retried');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testDelaySaturatesToTheCapForAFarResetTime(): void
    {
        self::assertSame(60 * 1_000_000, $this->delayFor($this->rateLimitException(3600)), 'a far reset must clamp the wait to exactly the 60s cap, in microseconds');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testDelayFloorsToOneSecondForAPastResetTime(): void
    {
        self::assertSame(1_000_000, $this->delayFor($this->rateLimitException(-100)), 'a past reset must floor the wait to exactly 1s, in microseconds');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testDelayTracksAResetTimeWithinTheCap(): void
    {
        $delay = $this->delayFor($this->rateLimitException(5));

        self::assertGreaterThanOrEqual(4 * 1_000_000, $delay);
        self::assertLessThanOrEqual(6 * 1_000_000, $delay);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testDelayFallsBackToTheCapForANonRateLimitException(): void
    {
        $other = new class('boom') extends RuntimeException implements ClientExceptionInterface {};

        self::assertSame(60 * 1_000_000, $this->delayFor($other), 'without a reset time to read, the wait must default to the 60s cap');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function pluginProperty(string $property): mixed
    {
        return ServiceMockHelper::getPrivateProperty(
            (new GitHubRateLimitRetryPluginFactory())->create(),
            $property
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function pluginCallback(string $property): callable
    {
        $callback = $this->pluginProperty($property);
        self::assertIsCallable($callback, \sprintf("the plugin's '%s' option must be callable", $property));

        return $callback;
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function delayFor(Exception $e): int
    {
        $delay = $this->pluginCallback('exceptionDelay')(new Request('GET', 'test'), $e, 0);
        self::assertIsInt($delay, 'the delay must be an integer number of microseconds');

        return $delay;
    }

    private function rateLimitException(int $resetInSeconds): ApiLimitExceedException
    {
        return new ApiLimitExceedException(5000, time() + $resetInSeconds);
    }
}
