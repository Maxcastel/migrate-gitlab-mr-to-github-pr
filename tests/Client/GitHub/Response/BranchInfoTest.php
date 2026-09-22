<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub\Response;

use App\Client\GitHub\Response\BranchInfo;
use App\Exception\Api\GitHubApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BranchInfoTest extends TestCase
{
    /**
     * @throws GitHubApiException
     */
    public function testReadsTheBranchNameAndTheCommitItPointsAt(): void
    {
        $branch = BranchInfo::buildFromApiResponse([
            'name' => 'main',
            'commit' => ['sha' => 'main-sha', 'url' => 'https://api.github.com/…'],
            'protected' => true,
        ], 'main');

        self::assertSame('main', $branch->name);
        self::assertSame('main-sha', $branch->commit->sha);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unusableBranchProvider(): iterable
    {
        yield 'name reported as a number' => [['name' => 42, 'commit' => ['sha' => 'main-sha']]];
        yield 'name absent' => [['commit' => ['sha' => 'main-sha']]];
        yield 'commit absent' => [['name' => 'main']];
        yield 'commit is not an object' => [['name' => 'main', 'commit' => 'main-sha']];
        yield 'commit without a sha' => [['name' => 'main', 'commit' => ['url' => 'https://api.github.com/…']]];
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws GitHubApiException
     */
    #[DataProvider('unusableBranchProvider')]
    public function testRejectsABranchItCannotResolveAndQuotesTheName(array $payload): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Unexpected GitHub payload for branch \'main\': "name" and "commit.sha" must be strings.');

        BranchInfo::buildFromApiResponse($payload, 'main');
    }
}
