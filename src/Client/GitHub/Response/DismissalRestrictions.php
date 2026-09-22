<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

/**
 * @see https://docs.github.com/rest/branches/branch-protection#get-branch-protection
 */
final readonly class DismissalRestrictions
{
    /**
     * @param list<LoginRef> $users
     * @param list<SlugRef>  $teams
     * @param list<SlugRef>  $apps
     */
    public function __construct(
        public array $users = [],
        public array $teams = [],
        public array $apps = [],
    ) {}

    /**
     * @param array<mixed> $payload
     */
    public static function buildFromApiResponse(array $payload): self
    {
        return new self(
            LoginRef::buildListFromApiResponse($payload['users'] ?? null),
            SlugRef::buildListFromApiResponse($payload['teams'] ?? null),
            SlugRef::buildListFromApiResponse($payload['apps'] ?? null),
        );
    }
}
