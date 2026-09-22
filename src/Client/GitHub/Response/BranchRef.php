<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

/**
 * @see https://docs.github.com/rest/pulls/pulls#list-pull-requests
 */
final readonly class BranchRef
{
    public function __construct(public string $ref) {}

    public static function buildFromApiResponse(mixed $payload): ?self
    {
        $ref = \is_array($payload) ? ($payload['ref'] ?? null) : null;

        return \is_string($ref) ? new self($ref) : null;
    }
}
