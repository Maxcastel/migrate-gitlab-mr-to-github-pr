<?php

declare(strict_types=1);

namespace App\Tests\Client\GitHub;

use App\Client\GitHub\GitHubAttachmentApiClient;
use App\Client\Http\HttpClientFactory;
use App\Exception\Api\GitHubApiException;
use ArrayObject;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class GitHubAttachmentApiClientTest extends TestCase
{
    private const ATTACHMENT_URL = 'https://github.com/user-attachments/assets/bcf3a3ca-a300-45a1-b291-7d25174d12fe';

    /** @var ArrayObject<int, RequestInterface> */
    private ArrayObject $requests;

    private ?bool $capturedSkipSsl = null;

    #[Override]
    protected function setUp(): void
    {
        $this->requests = new ArrayObject();
    }

    /**
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadingBeforeConnectFailsExplicitly(): void
    {
        $api = $this->unconnectedClient([]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('GitHub attachment client is not connected: call connect() first.');

        $api->upload(1296269, 'shot.png', 'image/png', 'PNG-BYTES');
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
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadAnswersTheAttachmentUrlToPutInTheDescription(): void
    {
        $api = $this->connectedClient([$this->jsonResponse('{"url":"'.self::ATTACHMENT_URL.'"}')]);

        self::assertSame(self::ATTACHMENT_URL, $api->upload(1296269, 'shot.png', 'image/png', 'PNG-BYTES'));
    }

    /**
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadPostsTheFileToTheEndpointTheWebInterfaceUses(): void
    {
        $api = $this->connectedClient([$this->jsonResponse('{"url":"'.self::ATTACHMENT_URL.'"}')]);

        $api->upload(1296269, 'shot.png', 'image/png', 'PNG-BYTES');

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('uploads.github.com', $request->getUri()->getHost());
        self::assertSame('/user-attachments/assets', $request->getUri()->getPath());
        self::assertSame('PNG-BYTES', (string) $request->getBody());
    }

    /**
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadDeclaresTheFileAndTheRepositoryInTheQuery(): void
    {
        $api = $this->connectedClient([$this->jsonResponse('{"url":"'.self::ATTACHMENT_URL.'"}')]);

        $api->upload(1296269, 'shot.png', 'image/png', 'PNG-BYTES');

        self::assertSame(
            'name=shot.png&content_type=image%2Fpng&repository_id=1296269',
            $this->lastRequest()->getUri()->getQuery()
        );
    }

    /**
     * A name GitLab writes for a pasted screenshot, which must survive the query string.
     *
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadEncodesAccentedAndSpacedFileNames(): void
    {
        $api = $this->connectedClient([$this->jsonResponse('{"url":"'.self::ATTACHMENT_URL.'"}')]);

        $api->upload(1296269, 'Capture d_écran 2026.jpg', 'image/jpeg', 'JPG-BYTES');

        self::assertStringContainsString(
            'name=Capture%20d_%C3%A9cran%202026.jpg',
            $this->lastRequest()->getUri()->getQuery(),
            'spaces must be percent-encoded, not turned into plus signs'
        );
    }

    /**
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadAuthenticatesWithTheGitHubToken(): void
    {
        $api = $this->connectedClient([$this->jsonResponse('{"url":"'.self::ATTACHMENT_URL.'"}')], 'my-github-token');

        $api->upload(1296269, 'shot.png', 'image/png', 'PNG-BYTES');

        $request = $this->lastRequest();
        self::assertSame('Bearer my-github-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('image/png', $request->getHeaderLine('Content-Type'));
    }

    /**
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadReadsTheHrefFieldWhenTheEndpointAnswersWithOne(): void
    {
        $api = $this->connectedClient([$this->jsonResponse('{"href":"'.self::ATTACHMENT_URL.'"}')]);

        self::assertSame(self::ATTACHMENT_URL, $api->upload(1296269, 'shot.png', 'image/png', 'PNG-BYTES'));
    }

    /**
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadReportsARefusedFile(): void
    {
        $api = $this->connectedClient([new Response(422, [], 'Unprocessable Entity')]);

        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage("Error uploading attachment 'shot.png' to GitHub:");

        $api->upload(1296269, 'shot.png', 'image/png', 'PNG-BYTES');
    }

    /**
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadReportsAnAnswerThatIsNotJson(): void
    {
        $api = $this->connectedClient([$this->jsonResponse('<html>not json</html>')]);

        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage("Malformed answer of GitHub while uploading attachment 'shot.png':");

        $api->upload(1296269, 'shot.png', 'image/png', 'PNG-BYTES');
    }

    /**
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function testUploadReportsAnAnswerThatIsNotAnObject(): void
    {
        $api = $this->connectedClient([$this->jsonResponse('42')]);

        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage("Malformed answer of GitHub while uploading attachment 'shot.png': expected a JSON object, got int.");

        $api->upload(1296269, 'shot.png', 'image/png', 'PNG-BYTES');
    }

    /**
     * @param list<Response> $responses
     */
    private function connectedClient(array $responses, string $token = 't', bool $skipSsl = false): GitHubAttachmentApiClient
    {
        $api = $this->unconnectedClient($responses);
        $api->connect($token, $skipSsl);

        return $api;
    }

    /**
     * @param list<Response> $responses
     */
    private function unconnectedClient(array $responses): GitHubAttachmentApiClient
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

        return new GitHubAttachmentApiClient($httpClientFactory);
    }

    private function jsonResponse(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    private function lastRequest(): RequestInterface
    {
        $requests = $this->requests->getArrayCopy();
        $lastRequest = end($requests);
        self::assertNotFalse($lastRequest, 'the upload must trigger an HTTP call');

        return $lastRequest;
    }
}
