<?php

declare(strict_types=1);

namespace App\Client\GitHub;

use App\Client\GitHub\Response\UploadedAttachment;
use App\Client\Http\HttpClientFactory;
use App\Exception\Api\GitHubApiException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use LogicException;

/**
 * Uploads a file to the endpoint the GitHub web interface calls when a file is dropped
 * into an issue, a pull request or a comment.
 *
 * This is the only way to obtain a https://github.com/user-attachments/assets/<uuid> URL,
 * which is the only image URL GitHub renders inside a private repository. The endpoint
 * belongs to the web interface rather than to the documented REST API, but it accepts a
 * regular bearer token, so no browser session is needed.
 */
class GitHubAttachmentApiClient
{
    private const NOT_CONNECTED = 'GitHub attachment client is not connected: call connect() first.';

    private const UPLOAD_URL = 'https://uploads.github.com/user-attachments/assets';

    private ?GuzzleClient $httpClient = null;

    private string $token = '';

    public function __construct(
        private HttpClientFactory $httpClientFactory = new HttpClientFactory(),
    ) {}

    public function connect(string $token, bool $skipSslCertificateVerification = false): void
    {
        $this->httpClient = $this->httpClientFactory->create($skipSslCertificateVerification);
        $this->token = $token;
    }

    /**
     * @throws LogicException
     */
    private function httpClient(): GuzzleClient
    {
        return $this->httpClient ?? throw new LogicException(self::NOT_CONNECTED);
    }

    /**
     * @return string the public https://github.com/user-attachments/assets/<uuid> URL
     *
     * @throws GitHubApiException
     * @throws LogicException
     */
    public function upload(int $repositoryId, string $fileName, string $contentType, string $contents): string
    {
        $url = self::UPLOAD_URL.'?'.http_build_query([
            'name' => $fileName,
            'content_type' => $contentType,
            'repository_id' => $repositoryId,
        ], '', '&', \PHP_QUERY_RFC3986);

        $httpClient = $this->httpClient();

        try {
            $response = $httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Accept' => 'application/json',
                    'Content-Type' => $contentType,
                ],
                'body' => $contents,
            ]);
        } catch (GuzzleException $guzzleException) {
            throw new GitHubApiException(\sprintf("Error uploading attachment '%s' to GitHub: %s", $fileName, $guzzleException->getMessage()), (int) $guzzleException->getCode(), $guzzleException);
        }

        try {
            $payload = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new GitHubApiException(\sprintf("Malformed answer of GitHub while uploading attachment '%s': %s", $fileName, $jsonException->getMessage()), $jsonException->getCode(), $jsonException);
        }

        if (!\is_array($payload)) {
            throw new GitHubApiException(\sprintf("Malformed answer of GitHub while uploading attachment '%s': expected a JSON object, got %s.", $fileName, get_debug_type($payload)));
        }

        return UploadedAttachment::buildFromApiResponse($payload)->url;
    }
}
