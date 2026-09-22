<?php

declare(strict_types=1);

namespace App\Client\GitLab;

use App\Client\GitLab\Api\MarkdownUploads;
use App\Client\Http\HttpClientFactory;
use Gitlab\Api\AbstractApi;
use Gitlab\Client;
use Gitlab\HttpClient\Builder;
use Gitlab\ResultPager;
use Http\Client\Exception;
use LogicException;

class GitLabApiClient
{
    private ?Client $client = null;

    public function __construct(
        private HttpClientFactory $httpClientFactory = new HttpClientFactory(),
    ) {}

    public function connect(string $token, bool $skipSslCertificateVerification = false): void
    {
        $this->client = new Client(new Builder($this->httpClientFactory->create($skipSslCertificateVerification)));
        $this->client->authenticate($token, Client::AUTH_HTTP_TOKEN);
    }

    /**
     * @throws LogicException
     */
    private function client(): Client
    {
        return $this->client ?? throw new LogicException('GitLab client is not connected: call connect() first.');
    }

    /**
     * @see https://docs.gitlab.com/api/issues/#list-all-project-issues
     *
     * @return list<array<mixed>>
     *
     * @throws Exception
     * @throws LogicException
     */
    public function fetchAllIssues(int $projectId): array
    {
        return $this->paginateAll($this->client()->issues(), 'all', [$projectId]);
    }

    /**
     * @see https://docs.gitlab.com/api/merge_requests/#list-project-merge-requests
     *
     * @return list<array<mixed>>
     *
     * @throws Exception
     * @throws LogicException
     */
    public function fetchAllMergeRequests(int $projectId): array
    {
        return $this->paginateAll($this->client()->mergeRequests(), 'all', [$projectId]);
    }

    /**
     * @see https://docs.gitlab.com/api/merge_requests/#retrieve-a-merge-request
     *
     * @return array<mixed>
     *
     * @throws LogicException
     */
    public function showMergeRequest(int $projectId, int $mergeRequestIid): array
    {
        $mergeRequest = $this->client()->mergeRequests()->show($projectId, $mergeRequestIid);

        return \is_array($mergeRequest) ? $mergeRequest : [];
    }

    /**
     * @see https://docs.gitlab.com/api/branches/#retrieve-a-repository-branch
     *
     * @return array<mixed>
     *
     * @throws LogicException
     */
    public function getBranch(int $projectId, string $branchName): array
    {
        $branch = $this->client()->repositories()->branch($projectId, $branchName);

        return \is_array($branch) ? $branch : [];
    }

    /**
     * Downloads a file attached to an issue or a merge request description.
     *
     * @see MarkdownUploads::download()
     *
     * @return string the raw content of the file
     *
     * @throws Exception
     * @throws LogicException
     */
    public function downloadUpload(int $projectId, string $secret, string $fileName): string
    {
        $response = (new MarkdownUploads($this->client()))->download($projectId, $secret, $fileName);

        return (string) $response->getBody();
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<mixed>>
     *
     * @throws Exception
     * @throws LogicException
     */
    private function paginateAll(AbstractApi $api, string $method, array $params): array
    {
        $items = [];
        foreach ((new ResultPager($this->client()))->fetchAll($api, $method, $params) as $item) {
            if (\is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
