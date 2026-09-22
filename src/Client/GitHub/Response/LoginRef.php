<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

/**
 * @see https://docs.github.com/rest/branches/branch-protection#get-branch-protection
 */
final readonly class LoginRef
{
    public function __construct(public string $login) {}

    /**
     * @return list<self>
     */
    public static function buildListFromApiResponse(mixed $payload): array
    {
        if (!\is_array($payload)) {
            return [];
        }

        $users = [];
        foreach ($payload as $entry) {
            $login = \is_array($entry) ? ($entry['login'] ?? null) : null;
            if (\is_string($login)) {
                $users[] = new self($login);
            }
        }

        return $users;
    }
}
