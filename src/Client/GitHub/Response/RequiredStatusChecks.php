<?php

declare(strict_types=1);

namespace App\Client\GitHub\Response;

/**
 * @see https://docs.github.com/rest/branches/branch-protection#get-branch-protection
 */
final readonly class RequiredStatusChecks
{
    /**
     * @param list<string>|null $contexts
     */
    public function __construct(
        public ?bool $strict = null,
        public ?array $contexts = null,
    ) {}

    /**
     * @param array<mixed> $payload
     */
    public static function buildFromApiResponse(array $payload): self
    {
        $strict = $payload['strict'] ?? null;
        $rawContexts = $payload['contexts'] ?? null;

        $contexts = null;
        if (\is_array($rawContexts)) {
            $contexts = [];
            foreach ($rawContexts as $context) {
                if (\is_string($context)) {
                    $contexts[] = $context;
                }
            }
        }

        return new self(\is_bool($strict) ? $strict : null, $contexts);
    }
}
