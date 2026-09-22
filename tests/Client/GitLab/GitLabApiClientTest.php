<?php

declare(strict_types=1);

namespace App\Tests\Client\GitLab;

use App\Client\GitLab\GitLabApiClient;
use App\Client\Http\HttpClientFactory;
use ArrayObject;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Http\Client\Exception;
use JsonException;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class GitLabApiClientTest extends TestCase
{
    /** @var ArrayObject<int, RequestInterface> */
    private ArrayObject $requests;

    #[Override]
    protected function setUp(): void
    {
        $this->requests = new ArrayObject();
    }

    private ?bool $capturedSkipSsl = null;

    /**
     * @throws Exception
     * @throws LogicException
     */
    public function testConnectSendsThePrivateTokenHeaderOnEverySubsequentRequest(): void
    {
        $api = $this->connectedClient([$this->jsonResponse('[]')], 'my-gitlab-token');

        $api->fetchAllMergeRequests(1);

        self::assertNotEmpty($this->requests, 'fetchAllMergeRequests must trigger an HTTP call');
        foreach ($this->requests as $i => $request) {
            self::assertSame('my-gitlab-token', $request->getHeaderLine('Private-Token'), \sprintf('Request #%d must carry the token configured by authenticate()', $i));
        }
    }

    public function testConnectDefaultsSkipSslToFalseSoVerifyIsEnabled(): void
    {
        $this->unconnectedClient([])->connect('t');

        self::assertFalse($this->capturedSkipSsl, 'default must be false (SSL verification ON)');
    }

    public function testConnectForwardsTheSkipSslFlagToTheHttpClientFactory(): void
    {
        $this->connectedClient([], 't', true);

        self::assertTrue($this->capturedSkipSsl, 'the requested skip-SSL flag must reach the HTTP client factory');
    }

    /**
     * @throws Exception
     * @throws LogicException
     */
    public function testCallingAnEndpointBeforeConnectFailsExplicitly(): void
    {
        $api = $this->unconnectedClient([]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('GitLab client is not connected: call connect() first.');

        $api->fetchAllIssues(42);
    }

    /**
     * @throws JsonException
     * @throws Exception
     * @throws LogicException
     */
    public function testFetchAllIssuesPaginatesTheProjectIssuesEndpoint(): void
    {
        $api = $this->connectedClient([
            $this->jsonResponse(json_encode([['iid' => 1, 'title' => 'First']], \JSON_THROW_ON_ERROR)),
        ]);

        self::assertSame([['iid' => 1, 'title' => 'First']], $api->fetchAllIssues(42));
        self::assertStringContainsString('/projects/42/issues', $this->lastUri());
    }

    /**
     * @throws JsonException
     * @throws Exception
     * @throws LogicException
     */
    public function testFetchAllMergeRequestsPaginatesTheProjectMergeRequestsEndpoint(): void
    {
        $api = $this->connectedClient([
            $this->jsonResponse(json_encode([['iid' => 7, 'title' => 'A MR']], \JSON_THROW_ON_ERROR)),
        ]);

        self::assertSame([['iid' => 7, 'title' => 'A MR']], $api->fetchAllMergeRequests(42));
        self::assertStringContainsString('/projects/42/merge_requests', $this->lastUri());
    }

    /**
     * @throws JsonException
     * @throws Exception
     * @throws LogicException
     */
    public function testFetchAllFollowsEveryPageOfResults(): void
    {
        $api = $this->connectedClient([
            new Response(
                200,
                [
                    'Content-Type' => 'application/json',
                    'Link' => '<https://gitlab.com/api/v4/projects/42/merge_requests?page=2>; rel="next"',
                ],
                json_encode([['iid' => 1]], \JSON_THROW_ON_ERROR)
            ),
            $this->jsonResponse(json_encode([['iid' => 2]], \JSON_THROW_ON_ERROR)),
        ]);

        self::assertSame([['iid' => 1], ['iid' => 2]], $api->fetchAllMergeRequests(42), 'every page must be followed and concatenated');
        self::assertCount(2, $this->requests, 'the second page must actually be requested');
    }

    /**
     * @throws JsonException
     * @throws LogicException
     */
    public function testShowMergeRequestReturnsTheSingleMergeRequestPayload(): void
    {
        $api = $this->connectedClient([
            $this->jsonResponse(json_encode(['iid' => 2, 'diff_refs' => ['base_sha' => 'abc']], \JSON_THROW_ON_ERROR)),
        ]);

        self::assertSame(['iid' => 2, 'diff_refs' => ['base_sha' => 'abc']], $api->showMergeRequest(1, 2));
        self::assertStringContainsString('/projects/1/merge_requests/2', $this->lastUri());
    }

    /**
     * @throws JsonException
     * @throws LogicException
     */
    public function testGetBranchReturnsTheBranchPayload(): void
    {
        $api = $this->connectedClient([
            $this->jsonResponse(json_encode(['name' => 'main', 'commit' => ['id' => 'head-sha']], \JSON_THROW_ON_ERROR)),
        ]);

        self::assertSame(['name' => 'main', 'commit' => ['id' => 'head-sha']], $api->getBranch(42, 'main'));
        self::assertStringContainsString('/projects/42/repository/branches/main', $this->lastUri());
    }

    /**
     * @throws Exception
     * @throws LogicException
     */
    public function testDownloadUploadReturnsTheRawFileOfTheProjectUploadEndpoint(): void
    {
        $api = $this->connectedClient([new Response(200, ['Content-Type' => 'image/png'], 'PNG-BYTES')]);

        self::assertSame('PNG-BYTES', $api->downloadUpload(42, '6fbaec24b893e88102ca3778336b36c3', 'shot.png'));
        self::assertStringContainsString('/api/v4/projects/42/uploads/6fbaec24b893e88102ca3778336b36c3/shot.png', $this->lastUri());
    }

    /**
     * @throws Exception
     * @throws LogicException
     */
    public function testDownloadUploadEncodesTheFileNameInThePath(): void
    {
        $api = $this->connectedClient([new Response(200, [], 'JPG-BYTES')]);

        $api->downloadUpload(42, '6fbaec24b893e88102ca3778336b36c3', 'Capture d_écran.jpg');

        self::assertStringContainsString('/uploads/6fbaec24b893e88102ca3778336b36c3/Capture%20d_%C3%A9cran.jpg', $this->lastUri());
    }

    /**
     * @param list<Response> $responses
     */
    private function connectedClient(array $responses, string $token = 't', bool $skipSsl = false): GitLabApiClient
    {
        $api = $this->unconnectedClient($responses);
        $api->connect($token, $skipSsl);

        return $api;
    }

    /**
     * @param list<Response> $responses
     */
    private function unconnectedClient(array $responses): GitLabApiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $requests = $this->requests;
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

        return new GitLabApiClient($httpClientFactory);
    }

    private function jsonResponse(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    private function lastUri(): string
    {
        $requests = $this->requests->getArrayCopy();
        $lastRequest = end($requests);
        self::assertNotFalse($lastRequest, 'no request has been sent yet');

        return (string) $lastRequest->getUri();
    }
}
