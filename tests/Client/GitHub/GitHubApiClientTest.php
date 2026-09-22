<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub;

use App\Client\GitHub\GitHubApiClient;
use App\Client\GitHub\Response\BranchInfo;
use App\Client\GitHub\Response\BranchProtection;
use App\Client\GitHub\Response\BranchRef;
use App\Client\GitHub\Response\CommitComparison;
use App\Client\GitHub\Response\CreatedCommit;
use App\Client\GitHub\Response\CreatedIssue;
use App\Client\GitHub\Response\CreatedPullRequest;
use App\Client\GitHub\Response\GitCommit;
use App\Client\GitHub\Response\GitCommitAuthor;
use App\Client\GitHub\Response\IssueSummary;
use App\Client\GitHub\Response\ProtectionToggle;
use App\Client\GitHub\Response\PullRequestSummary;
use App\Client\GitHub\Response\RepoCommit;
use App\Client\GitHub\Response\RepoCommitDetail;
use App\Client\GitHub\Response\ShaRef;
use App\Client\Http\GitHubRateLimitRetryPluginFactory;
use App\Client\Http\HttpClientFactory;
use App\Exception\Api\GitHubApiAuthenticationException;
use App\Exception\Api\GitHubApiConflictException;
use App\Exception\Api\GitHubApiException;
use App\Exception\Api\GitHubApiRateLimitException;
use App\Exception\Api\GitHubApiResourceNotFoundException;
use App\Exception\Api\GitHubApiServerException;
use App\Exception\Api\GitHubApiTimeoutException;
use App\Tests\Helper\ServiceMockHelper;
use ArrayObject;
use Github\Api\GitData;
use Github\Api\GitData\Commits;
use Github\Api\GitData\References;
use Github\Api\Issue;
use Github\Api\PullRequest;
use Github\Api\PullRequest\ReviewRequest;
use Github\Api\Repo;
use Github\Api\Repository\Protection;
use Github\Client;
use Github\Exception\ApiLimitExceedException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Client\Common\Plugin\RetryPlugin;
use InvalidArgumentException;
use Iterator;
use JsonException;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionException;
use ReflectionObject;
use RuntimeException;
use Throwable;

final class GitHubApiClientTest extends TestCase
{
    private GitHubApiClient $client;

    private Client&Stub $github;

    private ?bool $capturedSkipSsl = null;

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Override]
    protected function setUp(): void
    {
        $this->github = self::createStub(Client::class);
        $this->client = new GitHubApiClient();

        ServiceMockHelper::setPrivateProperty($this->client, 'client', $this->github);
        ServiceMockHelper::setPrivateProperty($this->client, 'userName', 'Maxcastel');
        ServiceMockHelper::setPrivateProperty($this->client, 'repositoryName', 'test-repo');
    }

    /**
     * @param ArrayObject<int, RequestInterface> $requests
     *
     * @return list<string>
     */
    private function recordedUris(ArrayObject $requests): array
    {
        return array_map(
            static fn (RequestInterface $request): string => (string) $request->getUri(),
            array_values($requests->getArrayCopy())
        );
    }

    /**
     * @param list<ResponseInterface|Throwable>  $responses
     * @param ArrayObject<int, RequestInterface> $requests
     */
    private function makeClientWithMockedHttp(array $responses, ArrayObject $requests = new ArrayObject()): GitHubApiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::tap(static function (RequestInterface $request) use ($requests): void {
            $requests[] = $request;
        }));

        $guzzle = new GuzzleClient(['handler' => $stack]);

        $httpClientFactory = self::createStub(HttpClientFactory::class);
        $httpClientFactory->method('create')->willReturnCallback(
            function (bool $skipSslCertificateVerification) use ($guzzle): GuzzleClient {
                $this->capturedSkipSsl = $skipSslCertificateVerification;

                return $guzzle;
            }
        );

        return new GitHubApiClient($httpClientFactory, $this->fastRetryPluginFactory());
    }

    private function fastRetryPluginFactory(): GitHubRateLimitRetryPluginFactory
    {
        $factory = self::createStub(GitHubRateLimitRetryPluginFactory::class);
        $factory->method('create')->willReturn(new RetryPlugin([
            'retries' => 5,
            'exception_decider' => static fn ($request, $e): bool => $e instanceof ApiLimitExceedException,
            'exception_delay' => static fn ($request, $e, int $retries): int => 0,
        ]));

        return $factory;
    }

    /**
     * @throws ReflectionException
     */
    private function exceptionWithStringCode(string $message, string $code): RuntimeException
    {
        $e = new RuntimeException($message);
        (new ReflectionObject($e))->getProperty('code')->setValue($e, $code);

        return $e;
    }

    /**
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testInitStoresUserNameAndRepositoryName(): void
    {
        $client = $this->makeClientWithMockedHttp([]);

        $client->connect('my-token', 'maxence', 'my-repo');

        self::assertSame('maxence', $client->getUserName());
        self::assertSame('my-repo', $client->getRepositoryName());
    }

    /**
     * @throws GitHubApiException
     */
    public function testInitSendsAuthorizationHeaderOnEverySubsequentRequest(): void
    {
        $requests = new ArrayObject();
        $client = $this->makeClientWithMockedHttp([
            new Response(200, ['Content-Type' => 'application/json'], '[]'),
        ], $requests);

        $client->connect('secret-token', 'u', 'r');
        $client->fetchAllIssues();

        self::assertNotEmpty($requests, 'fetchAllIssues must trigger an HTTP call');
        foreach ($requests as $i => $request) {
            $auth = $request->getHeaderLine('Authorization');
            self::assertSame('token secret-token', $auth, \sprintf('Request #%s must carry the bearer token configured by authenticate()', $i));
        }
    }

    /**
     * @throws GitHubApiException
     */
    public function testInitDefaultsSkipSslToFalseSoVerifyIsEnabled(): void
    {
        $client = $this->makeClientWithMockedHttp([]);

        $client->connect('t', 'u', 'r');

        self::assertFalse($this->capturedSkipSsl, 'default must be false (SSL verification ON)');
    }

    /**
     * @throws GitHubApiException
     */
    public function testInitForwardsTheSkipSslFlagToTheHttpClientFactory(): void
    {
        $client = $this->makeClientWithMockedHttp([]);

        $client->connect('t', 'u', 'r', true);

        self::assertTrue($this->capturedSkipSsl, 'the requested skip-SSL flag must reach the HTTP client factory');
    }

    /**
     * @throws GitHubApiException
     */
    public function testConnectThrowsGitHubApiExceptionWhenBuildingClientFails(): void
    {
        $retryPluginFactory = self::createStub(GitHubRateLimitRetryPluginFactory::class);
        $retryPluginFactory->method('create')->willThrowException(new RuntimeException('underlying client init failure'));
        $client = new GitHubApiClient(new HttpClientFactory(), $retryPluginFactory);

        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('Failed to connect to GitHub: underlying client init failure');

        $client->connect('t', 'u', 'r');
    }

    /**
     * @throws LogicException
     */
    public function testGetUserNameBeforeConnectFailsExplicitly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('GitHub client is not connected: call connect() first.');

        (new GitHubApiClient())->getUserName();
    }

    /**
     * @throws LogicException
     */
    public function testGetRepositoryNameBeforeConnectFailsExplicitly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('GitHub client is not connected: call connect() first.');

        (new GitHubApiClient())->getRepositoryName();
    }

    public function testCallingAnEndpointBeforeConnectFailsExplicitly(): void
    {
        try {
            (new GitHubApiClient())->fetchAllIssues();
            self::fail('an endpoint called before connect() must fail');
        } catch (GitHubApiException $gitHubApiException) {
            self::assertInstanceOf(LogicException::class, $gitHubApiException->getPrevious(), 'the misuse must be reported as such, and not as a failed API call');
            self::assertSame('GitHub client is not connected: call connect() first.', $gitHubApiException->getPrevious()->getMessage());
        }
    }

    /**
     * @throws JsonException
     * @throws GitHubApiException
     */
    public function testFetchAllIssuesReturnsOneSummaryPerIssue(): void
    {
        $client = $this->makeClientWithMockedHttp([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['number' => 7,  'title' => 'First issue'],
                ['number' => 12, 'title' => 'Second issue'],
            ], \JSON_THROW_ON_ERROR)),
        ]);
        $client->connect('t', 'u', 'r');

        self::assertEquals([
            new IssueSummary(7, 'First issue'),
            new IssueSummary(12, 'Second issue'),
        ], $client->fetchAllIssues());
    }

    /**
     * @throws JsonException
     * @throws GitHubApiException
     */
    public function testFetchAllPullRequestsKeepsNumberTitleAndTheSourceBranchName(): void
    {
        $client = $this->makeClientWithMockedHttp([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['number' => 3, 'title' => 'Add ripple', 'head' => ['ref' => 'feat-ripple', 'sha' => 'ignored']],
                ['number' => 4, 'title' => 'No head reported'],
            ], \JSON_THROW_ON_ERROR)),
        ]);
        $client->connect('t', 'u', 'r');

        self::assertEquals([
            new PullRequestSummary(3, 'Add ripple', new BranchRef('feat-ripple')),
            new PullRequestSummary(4, 'No head reported', null),
        ], $client->fetchAllPullRequests(), 'an entry without a usable head must stay usable for its number and title');
    }

    /**
     * @throws GitHubApiException
     */
    public function testFetchAllIssuesRequestsStateAllForTheConfiguredRepo(): void
    {
        $requests = new ArrayObject();
        $client = $this->makeClientWithMockedHttp([
            new Response(200, ['Content-Type' => 'application/json'], '[]'),
        ], $requests);
        $client->connect('t', 'maxence', 'repo');

        $client->fetchAllIssues();

        $uris = $this->recordedUris($requests);
        self::assertCount(1, $uris);
        $uri = $uris[0];
        self::assertStringContainsString('state=all', $uri, 'URI must include state=all');
        self::assertStringContainsString('/maxence/repo/', $uri, 'URI must address {user}/{repo}');
    }

    /**
     * @throws GitHubApiException
     */
    public function testFetchAllPullRequestsRequestsStateAllForTheConfiguredRepo(): void
    {
        $requests = new ArrayObject();
        $client = $this->makeClientWithMockedHttp([
            new Response(200, ['Content-Type' => 'application/json'], '[]'),
        ], $requests);
        $client->connect('t', 'maxence', 'repo');

        $client->fetchAllPullRequests();

        $uris = $this->recordedUris($requests);
        self::assertCount(1, $uris);
        $uri = $uris[0];
        self::assertStringContainsString('state=all', $uri, 'URI must include state=all');
        self::assertStringContainsString('/maxence/repo/', $uri, 'URI must address {user}/{repo}');
    }

    /**
     * @throws GitHubApiException
     */
    public function testFetchAllIssuesThrowsGitHubApiExceptionOnApiError(): void
    {
        $client = $this->makeClientWithMockedHttp([
            new Response(500, [], 'kaboom'),
        ]);
        $client->connect('t', 'u', 'r');

        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('GitHub API server error');

        $client->fetchAllIssues();
    }

    /**
     * @throws GitHubApiException
     */
    public function testFetchAllPullRequestsThrowsGitHubApiExceptionOnApiError(): void
    {
        $client = $this->makeClientWithMockedHttp([
            new Response(500, [], 'pulls bad'),
        ]);
        $client->connect('t', 'u', 'r');

        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('GitHub API server error');

        $client->fetchAllPullRequests();
    }

    /**
     * @param list<ResponseInterface|Throwable>  $responses
     * @param ArrayObject<int, RequestInterface> $requests
     */
    private function makeClientWithMockedHttpAndFastRetry(array $responses, ArrayObject $requests = new ArrayObject()): GitHubApiClient
    {
        return $this->makeClientWithMockedHttp($responses, $requests);
    }

    /**
     * @throws JsonException
     */
    private function secondaryRateLimitResponse(): Response
    {
        return new Response(
            403,
            [
                'Content-Type' => 'application/json',
                'X-RateLimit-Limit' => '5000',
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => '4102444800',
                'Retry-After' => '1',
            ],
            json_encode(['message' => 'You have exceeded a secondary rate limit. Please wait a few minutes before you try again.'], \JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @throws JsonException
     * @throws GitHubApiException
     */
    public function testRateLimitedRequestIsRetriedThenSucceeds(): void
    {
        $requests = new ArrayObject();
        $client = $this->makeClientWithMockedHttpAndFastRetry([
            $this->secondaryRateLimitResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['number' => 5, 'title' => 'Recovered issue'],
            ], \JSON_THROW_ON_ERROR)),
        ], $requests);

        $client->connect('t', 'u', 'r');

        $issues = $client->fetchAllIssues();

        self::assertCount(2, $requests, 'The rate-limited call must be retried once (2 requests total)');
        self::assertEquals([new IssueSummary(5, 'Recovered issue')], $issues, 'After the retry, the second (successful) response must be the one parsed');
    }

    /**
     * @throws GitHubApiException
     * @throws JsonException
     */
    public function testRateLimitRetryEventuallyGivesUpAfterMaxRetries(): void
    {
        $requests = new ArrayObject();
        $client = $this->makeClientWithMockedHttpAndFastRetry(
            array_fill(0, 6, $this->secondaryRateLimitResponse()),
            $requests
        );
        $client->connect('t', 'u', 'r');

        try {
            $client->fetchAllIssues();
            self::fail('fetchAllIssues should propagate the rate-limit failure once retries are exhausted');
        } catch (GitHubApiRateLimitException $gitHubApiRateLimitException) {
            $this->assertUpstreamMessageIsPrefixedWith('GitHub API rate limit exceeded: ', $gitHubApiRateLimitException);
        }

        self::assertCount(6, $requests, 'Initial attempt + 5 retries = 6 requests before giving up');
    }

    /**
     * @throws GitHubApiException
     */
    public function testFetchAllIssuesThrowsTimeoutExceptionOnConnectFailure(): void
    {
        $client = $this->makeClientWithMockedHttp([
            new ConnectException('Connection timed out', new Request('GET', 'test')),
        ]);
        $client->connect('t', 'u', 'r');

        try {
            $client->fetchAllIssues();
            self::fail('fetchAllIssues should surface a connect failure as a timeout');
        } catch (GitHubApiTimeoutException $gitHubApiTimeoutException) {
            $this->assertUpstreamMessageIsPrefixedWith('GitHub API request timed out: ', $gitHubApiTimeoutException);
        }
    }

    /**
     * @throws GitHubApiException
     */
    public function testFetchAllIssuesThrowsGenericExceptionOnUnmatchedErrorCode(): void
    {
        $client = $this->makeClientWithMockedHttp([
            new Response(400, [], 'bad request'),
        ]);
        $client->connect('t', 'u', 'r');

        try {
            $client->fetchAllIssues();
            self::fail('fetchAllIssues should fail on a 400');
        } catch (GitHubApiException $gitHubApiException) {
            $this->assertUpstreamMessageIsPrefixedWith('GitHub API request failed: ', $gitHubApiException);
        }
    }

    private function assertUpstreamMessageIsPrefixedWith(string $prefix, GitHubApiException $gitHubApiException): void
    {
        $previous = $gitHubApiException->getPrevious();

        self::assertNotNull($previous, 'the upstream exception must be chained as previous');
        self::assertNotSame('', $previous->getMessage(), 'the upstream message must not be empty, otherwise the assertion below proves nothing');
        self::assertSame($prefix.$previous->getMessage(), $gitHubApiException->getMessage());
    }

    /**
     * @return Iterator<string, array{int, class-string<GitHubApiException>, string}>
     */
    public static function httpStatusToExceptionProvider(): Iterator
    {
        yield '401 unauthorized => auth' => [401, GitHubApiAuthenticationException::class, 'GitHub API authentication failed: '];
        yield '403 forbidden => auth' => [403, GitHubApiAuthenticationException::class, 'GitHub API authentication failed: '];
        yield '404 not found => not found' => [404, GitHubApiResourceNotFoundException::class, 'GitHub API resource not found: '];
        yield '409 conflict => conflict' => [409, GitHubApiConflictException::class, 'GitHub API conflict: '];
        yield '422 unprocessable => conflict' => [422, GitHubApiConflictException::class, 'GitHub API conflict: '];
        yield '500 server error => server' => [500, GitHubApiServerException::class, 'GitHub API server error: '];
        yield '503 server error => server' => [503, GitHubApiServerException::class, 'GitHub API server error: '];
        yield '418 teapot => generic' => [418, GitHubApiException::class, 'GitHub API request failed: '];
    }

    /**
     * @param class-string<GitHubApiException> $expectedException
     *
     * @throws GitHubApiException
     */
    #[DataProvider('httpStatusToExceptionProvider')]
    public function testHandleApiExceptionMapsHttpStatusToDedicatedException(
        int $status,
        string $expectedException,
        string $expectedMessagePrefix,
    ): void {
        $client = $this->makeClientWithMockedHttp([
            new Response($status, [], 'boom'),
        ]);
        $client->connect('t', 'u', 'r');

        try {
            $client->fetchAllIssues();
            self::fail('fetchAllIssues should fail on a '.$status);
        } catch (GitHubApiException $gitHubApiException) {
            self::assertSame($gitHubApiException::class, $expectedException, \sprintf('HTTP %d must map to %s', $status, $expectedException));
            $this->assertUpstreamMessageIsPrefixedWith($expectedMessagePrefix, $gitHubApiException);
            self::assertSame($status, $gitHubApiException->getCode(), 'the original HTTP status must be preserved as the exception code');
        }
    }

    /**
     * @throws GitHubApiException
     */
    public function testNonRateLimitErrorIsNotRetried(): void
    {
        $requests = new ArrayObject();
        $client = $this->makeClientWithMockedHttpAndFastRetry([
            new Response(500, [], 'server error'),
        ], $requests);
        $client->connect('t', 'u', 'r');

        try {
            $client->fetchAllIssues();
            self::fail('fetchAllIssues should fail on a 500');
        } catch (GitHubApiException $gitHubApiException) {
            $this->assertUpstreamMessageIsPrefixedWith('GitHub API server error: ', $gitHubApiException);
        }

        self::assertCount(1, $requests, 'A 500 must not be retried');
    }

    public function testEndpointTypeMismatchIsReportedInsteadOfUsingTheWrongApi(): void
    {
        $this->github->method('api')->willReturn(self::createStub(Repo::class));

        try {
            $this->client->closeIssue(55);
            self::fail('a mismatched endpoint type must not be used as if it were the requested one');
        } catch (GitHubApiException $gitHubApiException) {
            self::assertStringContainsString(
                \sprintf('Expected the GitHub "issue" API to be a %s, got ', Issue::class),
                $gitHubApiException->getMessage()
            );
        }
    }

    /**
     * @throws GitHubApiException
     */
    public function testCreateIssueDelegatesToIssueApiAndReturnsResult(): void
    {
        $issueApi = $this->createIssueApi(['create']);
        $issueApi->expects(self::once())
            ->method('create')
            ->with('Maxcastel', 'test-repo', ['title' => 'X', 'body' => 'B'])
            ->willReturn(['number' => 100]);
        $this->github->method('api')->willReturn($issueApi);

        self::assertEquals(new CreatedIssue(100), $this->client->createIssue(['title' => 'X', 'body' => 'B']));
    }

    /**
     * @throws GitHubApiException
     */
    public function testCreateIssueThrowsGitHubApiExceptionOnApiError(): void
    {
        $issueApi = $this->createIssueApi(['create']);
        $issueApi->expects(self::once())->method('create')->willThrowException(new RuntimeException('boom', 500));
        $this->github->method('api')->willReturn($issueApi);

        $this->expectException(GitHubApiException::class);
        $this->client->createIssue(['title' => 'X']);
    }

    /**
     * @throws GitHubApiException
     */
    public function testCloseIssueUpdatesStateToClosed(): void
    {
        $issueApi = $this->createIssueApi(['update']);
        $issueApi->expects(self::once())
            ->method('update')
            ->with('Maxcastel', 'test-repo', 55, ['state' => 'closed']);
        $this->github->method('api')->willReturn($issueApi);

        $this->client->closeIssue(55);
    }

    /**
     * @throws GitHubApiException
     */
    public function testCloseIssueThrowsGitHubApiExceptionOnApiError(): void
    {
        $issueApi = $this->createIssueApi(['update']);
        $issueApi->expects(self::once())->method('update')->willThrowException(new RuntimeException('boom', 500));
        $this->github->method('api')->willReturn($issueApi);

        $this->expectException(GitHubApiException::class);
        $this->client->closeIssue(55);
    }

    /**
     * @throws GitHubApiException
     */
    public function testUpdateIssueAssigneesSendsAssigneesPayload(): void
    {
        $issueApi = $this->createIssueApi(['update']);
        $issueApi->expects(self::once())
            ->method('update')
            ->with('Maxcastel', 'test-repo', 7, ['assignees' => ['Maxcastel']]);
        $this->github->method('api')->willReturn($issueApi);

        $this->client->updateIssueAssignees(7, ['Maxcastel']);
    }

    /**
     * @throws GitHubApiException
     */
    public function testUpdateIssueAssigneesThrowsGitHubApiExceptionOnApiError(): void
    {
        $issueApi = $this->createIssueApi(['update']);
        $issueApi->expects(self::once())->method('update')->willThrowException(new RuntimeException('boom', 500));
        $this->github->method('api')->willReturn($issueApi);

        $this->expectException(GitHubApiException::class);
        $this->client->updateIssueAssignees(7, ['Maxcastel']);
    }

    /**
     * @throws GitHubApiException
     */
    public function testCreatePullRequestDelegatesAndReturnsResult(): void
    {
        $prApi = self::createStub(PullRequest::class);
        $captured = null;
        $prApi->method('create')->willReturnCallback(
            static function ($u, $r, array $params) use (&$captured): array {
                $captured = [$u, $r, $params];

                return ['number' => 7, 'head' => ['sha' => 'h']];
            }
        );
        $this->github->method('api')->willReturn($prApi);

        $result = $this->client->createPullRequest(['title' => 'T', 'base' => 'main']);

        self::assertEquals(new CreatedPullRequest(7, new ShaRef('h')), $result);
        self::assertSame(['Maxcastel', 'test-repo', ['title' => 'T', 'base' => 'main']], $captured);
    }

    /**
     * @throws GitHubApiException
     */
    public function testCreatePullRequestThrowsGitHubApiExceptionOnApiError(): void
    {
        $prApi = self::createStub(PullRequest::class);
        $prApi->method('create')->willThrowException(new RuntimeException('boom', 500));
        $this->github->method('api')->willReturn($prApi);

        $this->expectException(GitHubApiException::class);
        $this->client->createPullRequest(['title' => 'T']);
    }

    /**
     * @throws GitHubApiException
     */
    public function testClosePullRequestUpdatesStateToClosed(): void
    {
        $prApi = $this->getMockBuilder(PullRequest::class)
            ->disableOriginalConstructor()->onlyMethods(['update'])->getMock();
        $prApi->expects(self::once())
            ->method('update')
            ->with('Maxcastel', 'test-repo', 7, ['state' => 'closed']);
        $this->github->method('api')->willReturn($prApi);

        $this->client->closePullRequest(7);
    }

    /**
     * @throws GitHubApiException
     */
    public function testClosePullRequestThrowsGitHubApiExceptionOnApiError(): void
    {
        $prApi = $this->getMockBuilder(PullRequest::class)
            ->disableOriginalConstructor()->onlyMethods(['update'])->getMock();
        $prApi->expects(self::once())->method('update')->willThrowException(new RuntimeException('boom', 500));
        $this->github->method('api')->willReturn($prApi);

        $this->expectException(GitHubApiException::class);
        $this->client->closePullRequest(7);
    }

    /**
     * @throws GitHubApiException
     */
    public function testRequestReviewersDelegatesToReviewRequestsEndpoint(): void
    {
        $reviewRequests = $this->getMockBuilder(ReviewRequest::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $reviewRequests->expects(self::once())
            ->method('create')
            ->with('Maxcastel', 'test-repo', 7, ['Maxcastel']);

        $prApi = self::createStub(PullRequest::class);
        $prApi->method('reviewRequests')->willReturn($reviewRequests);
        $this->github->method('api')->willReturn($prApi);

        $this->client->requestReviewers(7, ['Maxcastel']);
    }

    /**
     * @throws GitHubApiException
     */
    public function testRequestReviewersThrowsGitHubApiExceptionOnApiError(): void
    {
        $reviewRequests = $this->getMockBuilder(ReviewRequest::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $reviewRequests->expects(self::once())->method('create')->willThrowException(new RuntimeException('boom', 500));

        $prApi = self::createStub(PullRequest::class);
        $prApi->method('reviewRequests')->willReturn($reviewRequests);
        $this->github->method('api')->willReturn($prApi);

        $this->expectException(GitHubApiException::class);
        $this->client->requestReviewers(7, ['Maxcastel']);
    }

    /**
     * @throws GitHubApiException
     */
    public function testCreateBranchCreatesRefUnderRefsHeadsPrefix(): void
    {
        $references = $this->getMockBuilder(References::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $references->expects(self::once())
            ->method('create')
            ->with('Maxcastel', 'test-repo', ['ref' => 'refs/heads/feat', 'sha' => 'abc']);

        $this->attachGitData($references, null);

        $this->client->createBranch('feat', 'abc');
    }

    /**
     * @throws GitHubApiException
     */
    public function testCreateBranchThrowsGitHubApiExceptionOnApiError(): void
    {
        $references = $this->getMockBuilder(References::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $references->expects(self::once())->method('create')->willThrowException(new RuntimeException('boom', 500));

        $this->attachGitData($references, null);

        $this->expectException(GitHubApiException::class);
        $this->client->createBranch('feat', 'abc');
    }

    /**
     * @throws GitHubApiException
     */
    public function testForceUpdateBranchUpdatesHeadsRefWithForceTrue(): void
    {
        $references = $this->getMockBuilder(References::class)
            ->disableOriginalConstructor()->onlyMethods(['update'])->getMock();
        $references->expects(self::once())
            ->method('update')
            ->with('Maxcastel', 'test-repo', 'heads/feat', ['sha' => 'abc', 'force' => true]);

        $this->attachGitData($references, null);

        $this->client->forceUpdateBranch('feat', 'abc');
    }

    /**
     * @throws GitHubApiException
     */
    public function testForceUpdateBranchThrowsGitHubApiExceptionOnApiError(): void
    {
        $references = $this->getMockBuilder(References::class)
            ->disableOriginalConstructor()->onlyMethods(['update'])->getMock();
        $references->expects(self::once())->method('update')->willThrowException(new RuntimeException('boom', 500));

        $this->attachGitData($references, null);

        $this->expectException(GitHubApiException::class);
        $this->client->forceUpdateBranch('feat', 'abc');
    }

    /**
     * @throws GitHubApiException
     */
    public function testCreateCommitDelegatesAndReturnsResult(): void
    {
        $commits = $this->getMockBuilder(Commits::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $params = ['message' => 'm', 'tree' => 't', 'parents' => ['p']];
        $commits->expects(self::once())
            ->method('create')
            ->with('Maxcastel', 'test-repo', $params)
            ->willReturn(['sha' => 'new-sha']);

        $this->attachGitData(null, $commits);

        self::assertEquals(new CreatedCommit('new-sha'), $this->client->createCommit($params));
    }

    /**
     * @throws GitHubApiException
     */
    public function testCreateCommitThrowsGitHubApiExceptionOnApiError(): void
    {
        $commits = $this->getMockBuilder(Commits::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $commits->expects(self::once())->method('create')->willThrowException(new RuntimeException('boom', 500));

        $this->attachGitData(null, $commits);

        $this->expectException(GitHubApiException::class);
        $this->client->createCommit(['message' => 'm']);
    }

    /**
     * @throws GitHubApiException
     */
    public function testShowCommitThrowsResourceNotFoundExceptionOn404(): void
    {
        $commits = $this->getMockBuilder(Commits::class)
            ->disableOriginalConstructor()->onlyMethods(['show'])->getMock();
        $commits->expects(self::once())->method('show')->willThrowException(new RuntimeException('Not Found', 404));

        $this->attachGitData(null, $commits);

        try {
            $this->client->showCommit('missing-sha');
            self::fail('showCommit should throw on a 404');
        } catch (GitHubApiResourceNotFoundException $gitHubApiResourceNotFoundException) {
            self::assertStringContainsString("GitHub commit 'missing-sha' not found", $gitHubApiResourceNotFoundException->getMessage());
            self::assertSame(404, $gitHubApiResourceNotFoundException->getCode(), 'the dedicated not-found exception must carry the 404 code');
        }
    }

    /**
     * @throws GitHubApiException
     */
    public function testShowCommitThrowsGitHubApiExceptionOnNon404Error(): void
    {
        $commits = $this->getMockBuilder(Commits::class)
            ->disableOriginalConstructor()->onlyMethods(['show'])->getMock();
        $commits->expects(self::once())->method('show')->willThrowException(new RuntimeException('boom', 500));

        $this->attachGitData(null, $commits);

        $this->expectException(GitHubApiException::class);
        $this->client->showCommit('sha1');
    }

    /**
     * @throws GitHubApiException
     */
    public function testShowCommitDelegatesAndNarrowsTheCommitPayload(): void
    {
        $commits = $this->getMockBuilder(Commits::class)
            ->disableOriginalConstructor()->onlyMethods(['show'])->getMock();
        $commits->expects(self::once())
            ->method('show')
            ->with('Maxcastel', 'test-repo', 'sha1')
            ->willReturn([
                'sha' => 'sha1',
                'tree' => ['sha' => 'tree-sha', 'url' => 'https://api.github.com/…'],
                'author' => ['name' => 'Alice', 'email' => 'alice@example.com', 'date' => '2025-09-08T10:00:00Z'],
                'parents' => [['sha' => 'p1'], ['sha' => 'p2']],
            ]);

        $this->attachGitData(null, $commits);

        self::assertEquals(
            new GitCommit(
                new ShaRef('tree-sha'),
                [new ShaRef('p1'), new ShaRef('p2')],
                new GitCommitAuthor('Alice', 'alice@example.com'),
            ),
            $this->client->showCommit('sha1'),
            'only the fields GitHubService consumes are kept, and each one is typed'
        );
    }

    /**
     * @throws GitHubApiException
     */
    public function testGetRepositoryIdReturnsTheNumericIdTheAttachmentEndpointNeeds(): void
    {
        $repoApi = $this->getMockBuilder(Repo::class)
            ->disableOriginalConstructor()->onlyMethods(['show'])->getMock();
        $repoApi->expects(self::once())
            ->method('show')
            ->with('Maxcastel', 'test-repo')
            ->willReturn(['id' => 1296269, 'name' => 'test-repo']);
        $this->github->method('api')->willReturn($repoApi);

        self::assertSame(1296269, $this->client->getRepositoryId());
    }

    /**
     * @throws GitHubApiException
     */
    public function testGetRepositoryIdThrowsGitHubApiExceptionOnApiError(): void
    {
        $repoApi = self::createStub(Repo::class);
        $repoApi->method('show')->willThrowException(new RuntimeException('Not Found', 404));
        $this->github->method('api')->willReturn($repoApi);

        $this->expectException(GitHubApiResourceNotFoundException::class);

        $this->client->getRepositoryId();
    }

    /**
     * @throws GitHubApiException
     */
    public function testGetBranchDelegatesAndReturnsResult(): void
    {
        $repoApi = $this->getMockBuilder(Repo::class)
            ->disableOriginalConstructor()->onlyMethods(['branches'])->getMock();
        $repoApi->expects(self::once())
            ->method('branches')
            ->with('Maxcastel', 'test-repo', 'feat')
            ->willReturn(['name' => 'feat', 'commit' => ['sha' => 's']]);
        $this->github->method('api')->willReturn($repoApi);

        self::assertEquals(new BranchInfo('feat', new ShaRef('s')), $this->client->getBranch('feat'));
    }

    /**
     * @throws GitHubApiException
     */
    public function testGetBranchThrowsGitHubApiExceptionPreservingHttpStatusCode(): void
    {
        $repoApi = self::createStub(Repo::class);
        $repoApi->method('branches')->willThrowException(new RuntimeException('Not Found', 404));
        $this->github->method('api')->willReturn($repoApi);

        try {
            $this->client->getBranch('missing');
            self::fail('getBranch should throw on a 404');
        } catch (GitHubApiResourceNotFoundException $gitHubApiResourceNotFoundException) {
            self::assertSame(404, $gitHubApiResourceNotFoundException->getCode());
            self::assertStringContainsString("GitHub branch 'missing' not found", $gitHubApiResourceNotFoundException->getMessage());
        }
    }

    /**
     * @throws GitHubApiException
     */
    public function testBranchExistsReturnsTrueWhenBranchApiSucceeds(): void
    {
        $repoApi = self::createStub(Repo::class);
        $repoApi->method('branches')->willReturn(['name' => 'feat', 'commit' => ['sha' => 's']]);
        $this->github->method('api')->willReturn($repoApi);

        self::assertTrue($this->client->branchExists('feat'));
    }

    /**
     * @throws GitHubApiException
     */
    public function testBranchExistsReturnsFalseWhenBranchIsMissing(): void
    {
        $repoApi = self::createStub(Repo::class);
        $repoApi->method('branches')->willThrowException(new RuntimeException('Not Found', 404));
        $this->github->method('api')->willReturn($repoApi);

        self::assertFalse($this->client->branchExists('missing'));
    }

    /**
     * @throws GitHubApiException
     */
    public function testBranchExistsPropagatesNon404Errors(): void
    {
        $repoApi = self::createStub(Repo::class);
        $repoApi->method('branches')->willThrowException(new RuntimeException('Forbidden', 403));
        $this->github->method('api')->willReturn($repoApi);

        $this->expectException(GitHubApiException::class);
        $this->client->branchExists('feat');
    }

    /**
     * @throws GitHubApiException
     */
    public function testCompareCommitsDelegatesAndNarrowsEveryCommit(): void
    {
        $author = ['name' => 'Alice', 'email' => 'alice@example.com', 'date' => '2025-09-08T10:00:00Z'];
        $repoCommits = $this->getMockBuilder(\Github\Api\Repository\Commits::class)
            ->disableOriginalConstructor()->onlyMethods(['compare'])->getMock();
        $repoCommits->expects(self::once())
            ->method('compare')
            ->with('Maxcastel', 'test-repo', 'base', 'head')
            ->willReturn(['commits' => [[
                'sha' => 'c',
                'html_url' => 'https://github.com/…',
                'commit' => [
                    'message' => 'feat: something',
                    'tree' => ['sha' => 'tree-sha'],
                    'author' => $author,
                    'committer' => $author,
                ],
                'parents' => [['sha' => 'p1']],
            ]]]);

        $repoApi = self::createStub(Repo::class);
        $repoApi->method('commits')->willReturn($repoCommits);
        $this->github->method('api')->willReturn($repoApi);

        self::assertEquals(
            new CommitComparison([
                new RepoCommit(
                    'c',
                    new RepoCommitDetail('feat: something', new ShaRef('tree-sha'), $author, $author),
                    [new ShaRef('p1')],
                ),
            ]),
            $this->client->compareCommits('base', 'head')
        );
    }

    /**
     * @throws GitHubApiException
     */
    public function testCompareCommitsThrowsGitHubApiExceptionOnApiError(): void
    {
        $repoCommits = $this->getMockBuilder(\Github\Api\Repository\Commits::class)
            ->disableOriginalConstructor()->onlyMethods(['compare'])->getMock();
        $repoCommits->expects(self::once())->method('compare')->willThrowException(new RuntimeException('boom', 500));

        $repoApi = self::createStub(Repo::class);
        $repoApi->method('commits')->willReturn($repoCommits);
        $this->github->method('api')->willReturn($repoApi);

        $this->expectException(GitHubApiException::class);
        $this->client->compareCommits('base', 'head');
    }

    /**
     * @throws GitHubApiException
     */
    public function testGetBranchProtectionReturnsConfigWhenBranchIsProtected(): void
    {
        $protection = $this->attachProtectionMock(['show']);
        $protection->expects(self::once())->method('show')
            ->with('Maxcastel', 'test-repo', 'main')
            ->willReturn(['enforce_admins' => ['enabled' => true]]);

        self::assertEquals(
            new BranchProtection(enforceAdmins: new ProtectionToggle(true)),
            $this->client->getBranchProtection('main')
        );
    }

    /**
     * @throws GitHubApiException
     */
    public function testGetBranchProtectionReturnsNullWhenBranchIsNotProtected(): void
    {
        $protection = $this->attachProtectionStub();
        $protection->method('show')->willThrowException(new RuntimeException('Branch not protected', 404));

        self::assertNull($this->client->getBranchProtection('main'));
    }

    /**
     * @throws GitHubApiException
     */
    public function testGetBranchProtectionRethrowsNon404Errors(): void
    {
        $protection = $this->attachProtectionStub();
        $protection->method('show')->willThrowException(new RuntimeException('Forbidden', 403));

        $this->expectException(GitHubApiException::class);
        $this->client->getBranchProtection('main');
    }

    /**
     * @throws GitHubApiException
     */
    public function testRemoveBranchProtectionDelegatesToTheApi(): void
    {
        $protection = $this->attachProtectionMock(['remove']);
        $protection->expects(self::once())->method('remove')
            ->with('Maxcastel', 'test-repo', 'main');

        $this->client->removeBranchProtection('main');
    }

    /**
     * @throws GitHubApiException
     */
    public function testRemoveBranchProtectionThrowsGitHubApiExceptionOnApiError(): void
    {
        $protection = $this->attachProtectionStub();
        $protection->method('remove')->willThrowException(new RuntimeException('boom', 500));

        $this->expectException(GitHubApiException::class);
        $this->client->removeBranchProtection('main');
    }

    /**
     * @throws GitHubApiException
     */
    public function testUpdateBranchProtectionForwardsPayloadVerbatim(): void
    {
        $payload = ['enforce_admins' => false, 'allow_force_pushes' => false];
        $protection = $this->attachProtectionMock(['update']);
        $protection->expects(self::once())->method('update')
            ->with('Maxcastel', 'test-repo', 'main', $payload);

        $this->client->updateBranchProtection('main', $payload);
    }

    /**
     * @throws GitHubApiException
     */
    public function testUpdateBranchProtectionThrowsGitHubApiExceptionOnApiError(): void
    {
        $protection = $this->attachProtectionStub();
        $protection->method('update')->willThrowException(new RuntimeException('boom', 500));

        $this->expectException(GitHubApiException::class);
        $this->client->updateBranchProtection('main', ['enforce_admins' => false]);
    }

    /**
     * @throws GitHubApiException
     * @throws ReflectionException
     */
    public function testShowCommitCastsAStringStatusCodeBeforeThe404Check(): void
    {
        $commits = self::createStub(Commits::class);
        $commits->method('show')->willThrowException($this->exceptionWithStringCode('Not Found', '404'));

        $this->attachGitData(null, $commits);

        try {
            $this->client->showCommit('missing-sha');
            self::fail('showCommit should throw on a 404');
        } catch (GitHubApiResourceNotFoundException $gitHubApiResourceNotFoundException) {
            self::assertStringContainsString("GitHub commit 'missing-sha' not found", $gitHubApiResourceNotFoundException->getMessage());
            self::assertSame(404, $gitHubApiResourceNotFoundException->getCode());
        }
    }

    /**
     * @throws GitHubApiException
     * @throws ReflectionException
     */
    public function testGetBranchCastsAStringStatusCodeBeforeThe404Check(): void
    {
        $repoApi = self::createStub(Repo::class);
        $repoApi->method('branches')->willThrowException($this->exceptionWithStringCode('Not Found', '404'));
        $this->github->method('api')->willReturn($repoApi);

        try {
            $this->client->getBranch('missing');
            self::fail('getBranch should throw on a 404');
        } catch (GitHubApiResourceNotFoundException $gitHubApiResourceNotFoundException) {
            self::assertStringContainsString("GitHub branch 'missing' not found", $gitHubApiResourceNotFoundException->getMessage());
            self::assertSame(404, $gitHubApiResourceNotFoundException->getCode());
        }
    }

    /**
     * @throws ReflectionException
     * @throws GitHubApiException
     */
    public function testGetBranchProtectionCastsAStringStatusCodeBeforeThe404Check(): void
    {
        $protection = $this->attachProtectionStub();
        $protection->method('show')->willThrowException($this->exceptionWithStringCode('Branch not protected', '404'));

        self::assertNull($this->client->getBranchProtection('main'));
    }

    /**
     * @throws GitHubApiException
     * @throws ReflectionException
     */
    public function testHandleApiExceptionCastsAStringStatusCodeBeforeMatching(): void
    {
        $issueApi = self::createStub(Issue::class);
        $issueApi->method('create')->willThrowException($this->exceptionWithStringCode('nope', '401'));
        $this->github->method('api')->willReturn($issueApi);

        $this->expectException(GitHubApiAuthenticationException::class);
        $this->client->createIssue(['title' => 'X']);
    }

    /**
     * @param list<non-empty-string> $methods
     */
    private function createIssueApi(array $methods): MockObject
    {
        return $this->getMockBuilder(Issue::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }

    private function attachGitData(?object $references, ?object $commits): void
    {
        $gitDataApi = self::createStub(GitData::class);
        if (null !== $references) {
            $gitDataApi->method('references')->willReturn($references);
        }

        if (null !== $commits) {
            $gitDataApi->method('commits')->willReturn($commits);
        }

        $this->github->method('api')->willReturn($gitDataApi);
    }

    /**
     * @return Protection&Stub
     */
    private function attachProtectionStub(): Protection
    {
        $protection = self::createStub(Protection::class);
        $this->attachProtection($protection);

        return $protection;
    }

    /**
     * @param list<non-empty-string> $methods
     *
     * @return Protection&MockObject
     */
    private function attachProtectionMock(array $methods): Protection
    {
        $protection = $this->getMockBuilder(Protection::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
        $this->attachProtection($protection);

        return $protection;
    }

    private function attachProtection(Protection $protection): void
    {
        $repoApi = self::createStub(Repo::class);
        $repoApi->method('protection')->willReturn($protection);
        $this->github->method('api')->willReturn($repoApi);
    }
}
