<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\BranchRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BranchRefTest extends TestCase
{
    public function testReadsTheSourceBranchName(): void
    {
        $head = BranchRef::buildFromApiResponse(['ref' => 'feat-ripple', 'sha' => 'ignored', 'label' => 'me:feat-ripple']);

        self::assertNotNull($head);
        self::assertSame('feat-ripple', $head->ref);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableHeadProvider(): iterable
    {
        yield 'no head reported' => [null];
        yield 'head is not an object' => ['refs/heads/feat'];
        yield 'head without a ref' => [['sha' => 'abc123']];
        yield 'ref reported as a number' => [['ref' => 42]];
    }

    #[DataProvider('unusableHeadProvider')]
    public function testYieldsNothingRatherThanFailingOnAnUnusableHead(mixed $payload): void
    {
        self::assertNull(BranchRef::buildFromApiResponse($payload));
    }
}
