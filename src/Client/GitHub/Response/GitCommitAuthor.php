<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

/**
 * @see https://docs.github.com/rest/git/commits#get-a-commit-object
 */
final readonly class GitCommitAuthor
{
    public function __construct(
        public ?string $name,
        public ?string $email,
    ) {}

    public static function buildFromApiResponse(mixed $payload): ?self
    {
        if (!\is_array($payload)) {
            return null;
        }

        $name = $payload['name'] ?? null;
        $email = $payload['email'] ?? null;

        return new self(
            \is_string($name) ? $name : null,
            \is_string($email) ? $email : null,
        );
    }
}
