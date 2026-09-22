<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

/**
 * @see https://docs.github.com/rest/branches/branch-protection#get-branch-protection
 */
final readonly class ProtectionToggle
{
    public function __construct(public bool $enabled) {}

    public static function buildFromApiResponse(mixed $payload): ?self
    {
        $enabled = \is_array($payload) ? ($payload['enabled'] ?? null) : null;

        return \is_bool($enabled) ? new self($enabled) : null;
    }
}
