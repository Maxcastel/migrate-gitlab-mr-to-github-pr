<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

/**
 * @see https://docs.github.com/rest/branches/branch-protection#get-branch-protection
 */
final readonly class SlugRef
{
    public function __construct(public string $slug) {}

    /**
     * @return list<self>
     */
    public static function buildListFromApiResponse(mixed $payload): array
    {
        if (!\is_array($payload)) {
            return [];
        }

        $entities = [];
        foreach ($payload as $entry) {
            $slug = \is_array($entry) ? ($entry['slug'] ?? null) : null;
            if (\is_string($slug)) {
                $entities[] = new self($slug);
            }
        }

        return $entities;
    }
}
