<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitCredentials;
use Override;
use PHPUnit\Framework\TestCase;

final class GitCredentialsTest extends TestCase
{
    private GitCredentials $credentials;

    #[Override]
    protected function setUp(): void
    {
        $this->credentials = new GitCredentials();
    }

    public function testAddsTokenToPlainHttpsUrl(): void
    {
        self::assertSame(
            'https://tok123@github.com/user/repo.git',
            $this->credentials->addTokenToUrl('https://github.com/user/repo.git', 'tok123')
        );
    }

    public function testLeavesUrlAloneIfAlreadyAuthenticated(): void
    {
        $url = 'https://existing-token@github.com/user/repo.git';
        self::assertSame($url, $this->credentials->addTokenToUrl($url, 'new-token'));
    }

    public function testConvertsSshUrlToTokenizedHttps(): void
    {
        self::assertSame(
            'https://tok123@github.com/Maxcastel/test.git',
            $this->credentials->addTokenToUrl('git@github.com:Maxcastel/test.git', 'tok123')
        );
    }

    public function testNonHttpsNonSshUrlIsReturnedUnchanged(): void
    {
        $url = 'file:///tmp/mirror.git';
        self::assertSame($url, $this->credentials->addTokenToUrl($url, 'tok'));
    }

    public function testTokenWithSpecialCharsIsInsertedVerbatim(): void
    {
        self::assertSame(
            'https://tok-with_underscore.123@github.com/x/y.git',
            $this->credentials->addTokenToUrl('https://github.com/x/y.git', 'tok-with_underscore.123')
        );
    }

    public function testRedactsGitHubTokenUserinfoFromUrl(): void
    {
        self::assertSame(
            'git push https://***@github.com/Maxcastel/repo.git failed: denied',
            $this->credentials->redact('git push https://ghp_SECRET123@github.com/Maxcastel/repo.git failed: denied')
        );
    }

    public function testRedactsGitLabOauthTokenUserinfoFromUrl(): void
    {
        self::assertSame(
            'fatal: clone https://***@gitlab.com/group/project.git',
            $this->credentials->redact('fatal: clone https://oauth2:glpat-SECRET@gitlab.com/group/project.git')
        );
    }

    public function testRedactDoesNotLeakAnyKnownTokenPrefix(): void
    {
        $redacted = $this->credentials->redact(
            'https://oauth2:glpat-abc@gitlab.com/x.git https://ghp_def@github.com/y.git'
        );

        self::assertStringNotContainsString('glpat-abc', $redacted);
        self::assertStringNotContainsString('ghp_def', $redacted);
        self::assertStringNotContainsString('oauth2:', $redacted);
    }

    public function testRedactLeavesTextWithoutCredentialsUntouched(): void
    {
        $text = 'git for-each-ref refs/heads/ failed: not a git repository';
        self::assertSame($text, $this->credentials->redact($text));
    }
}
