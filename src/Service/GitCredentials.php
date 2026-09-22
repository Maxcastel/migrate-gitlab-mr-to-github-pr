<?php

declare(strict_types=1);

namespace App\Service;

class GitCredentials
{
    public function addTokenToUrl(string $url, string $token): string
    {
        if (str_contains($url, '@') && str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, 'https://')) {
            return str_replace('https://', 'https://'.$token.'@', $url);
        }

        if (str_starts_with($url, 'git@')) {
            $url = preg_replace('/git@github\.com:/', 'https://github.com/', $url) ?? $url;

            return str_replace('https://', 'https://'.$token.'@', $url);
        }

        return $url;
    }

    /**
     * Replace the userinfo part of any URL (token, user:password, oauth2:token)
     * with "***" so credentials never reach an exception message or log.
     */
    public function redact(string $text): string
    {
        return preg_replace('#(://)[^/@\s]+@#', '$1***@', $text) ?? $text;
    }
}
