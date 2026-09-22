<?php

declare(strict_types=1);

namespace App\Client\GitHub;

use App\Client\GitHub\Response\BranchInfo;
use App\Client\GitHub\Response\BranchProtection;
use App\Client\GitHub\Response\CommitComparison;
use App\Client\GitHub\Response\CreatedCommit;
use App\Client\GitHub\Response\CreatedIssue;
use App\Client\GitHub\Response\CreatedPullRequest;
use App\Client\GitHub\Response\GitCommit;
use App\Client\GitHub\Response\IssueSummary;
use App\Client\GitHub\Response\PullRequestSummary;
use App\Client\GitHub\Response\RepositoryInfo;
use App\Client\Http\GitHubRateLimitRetryPluginFactory;
use App\Client\Http\HttpClientFactory;
use App\Exception\Api\GitHubApiAuthenticationException;
use App\Exception\Api\GitHubApiConflictException;
use App\Exception\Api\GitHubApiException;
use App\Exception\Api\GitHubApiRateLimitException;
use App\Exception\Api\GitHubApiResourceNotFoundException;
use App\Exception\Api\GitHubApiServerException;
use App\Exception\Api\GitHubApiTimeoutException;
use Exception;
use Github\Api\AbstractApi;
use Github\Api\GitData;
use Github\Api\Issue;
use Github\Api\PullRequest;
use Github\Api\Repo;
use Github\AuthMethod;
use Github\Client;
use Github\Exception\ApiLimitExceedException;
use Github\Exception\InvalidArgumentException;
use Github\HttpClient\Builder;
use Github\ResultPager;
use GuzzleHttp\Exception\ConnectException;
use LogicException;

class GitHubApiClient
{
    private const NOT_CONNECTED = 'GitHub client is not connected: call connect() first.';

    private ?Client $client = null;

    private ?string $userName = null;

    private ?string $repositoryName = null;

    public function __construct(
        private HttpClientFactory $httpClientFactory = new HttpClientFactory(),
        private GitHubRateLimitRetryPluginFactory $retryPluginFactory = new GitHubRateLimitRetryPluginFactory(),
    ) {}

    /**
     * @throws GitHubApiException
     */
    public function connect(
        string $token,
        string $userName,
        string $repositoryName,
        bool $skipSslCertificateVerification = false,
    ): void {
        try {
            $builder = new Builder($this->httpClientFactory->create($skipSslCertificateVerification));
            $builder->addPlugin($this->retryPluginFactory->create());

            $this->client = new Client($builder);
            $this->client->authenticate($token, null, AuthMethod::ACCESS_TOKEN);
        } catch (Exception $exception) {
            throw new GitHubApiException('Failed to connect to GitHub: '.$exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $this->userName = $userName;
        $this->repositoryName = $repositoryName;
    }

    /**
     * @throws LogicException
     */
    public function getUserName(): string
    {
        return $this->userName ?? throw new LogicException(self::NOT_CONNECTED);
    }

    /**
     * @throws LogicException
     */
    public function getRepositoryName(): string
    {
        return $this->repositoryName ?? throw new LogicException(self::NOT_CONNECTED);
    }

    /**
     * @throws LogicException
     */
    private function client(): Client
    {
        return $this->client ?? throw new LogicException(self::NOT_CONNECTED);
    }

    /**
     * @template T of AbstractApi
     *
     * @param class-string<T> $endpoint
     *
     * @return T
     *
     * @throws GitHubApiException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function api(string $name, string $endpoint): AbstractApi
    {
        $api = $this->client()->api($name);

        if (!$api instanceof $endpoint) {
            throw new GitHubApiException(\sprintf('Expected the GitHub "%s" API to be a %s, got %s.', $name, $endpoint, $api::class));
        }

        return $api;
    }

    /**
     * @throws GitHubApiException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function issueApi(): Issue
    {
        return $this->api('issue', Issue::class);
    }

    /**
     * @throws GitHubApiException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function pullRequestApi(): PullRequest
    {
        return $this->api('pull_request', PullRequest::class);
    }

    /**
     * @throws GitHubApiException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function gitDataApi(): GitData
    {
        return $this->api('git_data', GitData::class);
    }

    /**
     * @throws GitHubApiException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function repoApi(): Repo
    {
        return $this->api('repo', Repo::class);
    }

    /**
     * @throws GitHubApiAuthenticationException
     * @throws GitHubApiConflictException
     * @throws GitHubApiException
     * @throws GitHubApiRateLimitException
     * @throws GitHubApiResourceNotFoundException
     * @throws GitHubApiServerException
     * @throws GitHubApiTimeoutException
     */
    private function handleApiException(Exception $e): never
    {
        if ($e instanceof ApiLimitExceedException) {
            throw new GitHubApiRateLimitException('GitHub API rate limit exceeded: '.$e->getMessage(), (int) $e->getCode(), $e);
        }

        if ($e instanceof ConnectException) {
            throw new GitHubApiTimeoutException('GitHub API request timed out: '.$e->getMessage(), (int) $e->getCode(), $e);
        }

        $code = (int) $e->getCode();

        throw match (true) {
            401 === $code || 403 === $code => new GitHubApiAuthenticationException('GitHub API authentication failed: '.$e->getMessage(), $code, $e),
            404 === $code => new GitHubApiResourceNotFoundException('GitHub API resource not found: '.$e->getMessage(), $code, $e),
            409 === $code || 422 === $code => new GitHubApiConflictException('GitHub API conflict: '.$e->getMessage(), $code, $e),
            $code >= 500 => new GitHubApiServerException('GitHub API server error: '.$e->getMessage(), $code, $e),
            default => new GitHubApiException('GitHub API request failed: '.$e->getMessage(), $code, $e),
        };
    }

    /**
     * @see https://docs.github.com/rest/issues/issues#list-repository-issues
     *
     * @return list<IssueSummary>
     *
     * @throws GitHubApiException
     */
    public function fetchAllIssues(): array
    {
        try {
            $issues = $this->paginateAll(
                $this->issueApi(),
                'all',
                [$this->getUserName(), $this->getRepositoryName(), ['state' => 'all']]
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }

        $summaries = [];
        foreach ($issues as $issue) {
            $summaries[] = IssueSummary::buildFromApiResponse($issue);
        }

        return $summaries;
    }

    /**
     * @see https://docs.github.com/rest/pulls/pulls#list-pull-requests
     *
     * @return list<PullRequestSummary>
     *
     * @throws GitHubApiException
     */
    public function fetchAllPullRequests(): array
    {
        try {
            $pullRequests = $this->paginateAll(
                $this->pullRequestApi(),
                'all',
                [$this->getUserName(), $this->getRepositoryName(), ['state' => 'all']]
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }

        $summaries = [];
        foreach ($pullRequests as $pullRequest) {
            $summaries[] = PullRequestSummary::buildFromApiResponse($pullRequest);
        }

        return $summaries;
    }

    /**
     * @see https://docs.github.com/rest/issues/issues#create-an-issue
     *
     * @param array<string, mixed> $params
     *
     * @throws GitHubApiException
     */
    public function createIssue(array $params): CreatedIssue
    {
        try {
            $issue = $this->issueApi()->create(
                $this->getUserName(), $this->getRepositoryName(), $params
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }

        return CreatedIssue::buildFromApiResponse($issue);
    }

    /**
     * @see https://docs.github.com/rest/issues/issues#update-an-issue
     *
     * @throws GitHubApiException
     */
    public function closeIssue(int $issueNumber): void
    {
        try {
            $this->issueApi()->update(
                $this->getUserName(), $this->getRepositoryName(), $issueNumber, ['state' => 'closed']
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }
    }

    /**
     * @see https://docs.github.com/rest/issues/issues#update-an-issue
     *
     * @param list<string> $assignees
     *
     * @throws GitHubApiException
     */
    public function updateIssueAssignees(int $issueNumber, array $assignees): void
    {
        try {
            $this->issueApi()->update(
                $this->getUserName(), $this->getRepositoryName(), $issueNumber, ['assignees' => $assignees]
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }
    }

    /**
     * @see https://docs.github.com/rest/pulls/pulls#create-a-pull-request
     *
     * @param array<string, mixed> $params
     *
     * @throws GitHubApiException
     */
    public function createPullRequest(array $params): CreatedPullRequest
    {
        try {
            $pullRequest = $this->pullRequestApi()->create(
                $this->getUserName(), $this->getRepositoryName(), $params
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }

        return CreatedPullRequest::buildFromApiResponse($pullRequest);
    }

    /**
     * @see https://docs.github.com/rest/pulls/pulls#update-a-pull-request
     *
     * @throws GitHubApiException
     */
    public function closePullRequest(int $pullRequestNumber): void
    {
        try {
            $this->pullRequestApi()->update(
                $this->getUserName(), $this->getRepositoryName(), $pullRequestNumber, ['state' => 'closed']
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }
    }

    /**
     * @see https://docs.github.com/rest/pulls/review-requests#request-reviewers-for-a-pull-request
     *
     * @param list<string> $reviewers
     *
     * @throws GitHubApiException
     */
    public function requestReviewers(int $pullRequestNumber, array $reviewers): void
    {
        try {
            $this->pullRequestApi()->reviewRequests()->create(
                $this->getUserName(), $this->getRepositoryName(), $pullRequestNumber, $reviewers
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }
    }

    /**
     * @see https://docs.github.com/rest/git/refs#create-a-reference
     *
     * @throws GitHubApiException
     */
    public function createBranch(string $branchName, string $sha): void
    {
        try {
            $this->gitDataApi()->references()->create(
                $this->getUserName(), $this->getRepositoryName(),
                ['ref' => 'refs/heads/'.$branchName, 'sha' => $sha]
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }
    }

    /**
     * @see https://docs.github.com/rest/git/refs#update-a-reference
     *
     * @throws GitHubApiException
     */
    public function forceUpdateBranch(string $branchName, string $sha): void
    {
        try {
            $this->gitDataApi()->references()->update(
                $this->getUserName(), $this->getRepositoryName(),
                'heads/'.$branchName, ['sha' => $sha, 'force' => true]
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }
    }

    /**
     * @see https://docs.github.com/rest/git/commits#create-a-commit
     *
     * @param array<string, mixed> $params
     *
     * @throws GitHubApiException
     */
    public function createCommit(array $params): CreatedCommit
    {
        try {
            $commit = $this->gitDataApi()->commits()->create(
                $this->getUserName(), $this->getRepositoryName(), $params
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }

        return CreatedCommit::buildFromApiResponse($commit);
    }

    /**
     * @see https://docs.github.com/rest/git/commits#get-a-commit-object
     *
     * @throws GitHubApiException
     * @throws GitHubApiResourceNotFoundException
     */
    public function showCommit(string $sha): GitCommit
    {
        try {
            $commit = $this->gitDataApi()->commits()->show(
                $this->getUserName(), $this->getRepositoryName(), $sha
            );
        } catch (Exception $exception) {
            if (404 === (int) $exception->getCode()) {
                throw new GitHubApiResourceNotFoundException(\sprintf("GitHub commit '%s' not found: %s", $sha, $exception->getMessage()), 404, $exception);
            }

            $this->handleApiException($exception);
        }

        return GitCommit::buildFromApiResponse($commit, $sha);
    }

    /**
     * @see https://docs.github.com/rest/branches/branches#get-a-branch
     *
     * @throws GitHubApiException
     * @throws GitHubApiResourceNotFoundException
     */
    public function getBranch(string $branchName): BranchInfo
    {
        try {
            $branch = $this->repoApi()->branches(
                $this->getUserName(), $this->getRepositoryName(), $branchName
            );
        } catch (Exception $exception) {
            if (404 === (int) $exception->getCode()) {
                throw new GitHubApiResourceNotFoundException(\sprintf("GitHub branch '%s' not found: %s", $branchName, $exception->getMessage()), 404, $exception);
            }

            $this->handleApiException($exception);
        }

        return BranchInfo::buildFromApiResponse($branch, $branchName);
    }

    /**
     * @throws GitHubApiException
     */
    public function branchExists(string $branchName): bool
    {
        try {
            $this->getBranch($branchName);

            return true;
        } catch (GitHubApiResourceNotFoundException) {
            return false;
        }
    }

    /**
     * @see https://docs.github.com/rest/commits/commits#compare-two-commits
     *
     * @throws GitHubApiException
     */
    public function compareCommits(string $base, string $head): CommitComparison
    {
        try {
            $comparison = $this->repoApi()->commits()->compare(
                $this->getUserName(), $this->getRepositoryName(), $base, $head
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }

        return CommitComparison::buildFromApiResponse($comparison, $base, $head);
    }

    /**
     * @see https://docs.github.com/rest/branches/branch-protection#get-branch-protection
     *
     * @throws GitHubApiException
     */
    public function getBranchProtection(string $branch): ?BranchProtection
    {
        try {
            $protection = $this->repoApi()->protection()->show(
                $this->getUserName(), $this->getRepositoryName(), $branch
            );
        } catch (Exception $exception) {
            if (404 === (int) $exception->getCode()) {
                return null;
            }

            $this->handleApiException($exception);
        }

        return BranchProtection::buildFromApiResponse($protection);
    }

    /**
     * @see https://docs.github.com/rest/branches/branch-protection#delete-branch-protection
     *
     * @throws GitHubApiException
     */
    public function removeBranchProtection(string $branch): void
    {
        try {
            $this->repoApi()->protection()->remove(
                $this->getUserName(), $this->getRepositoryName(), $branch
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }
    }

    /**
     * @see https://docs.github.com/rest/branches/branch-protection#update-branch-protection
     *
     * @param array<string, mixed> $protectionPayload
     *
     * @throws GitHubApiException
     */
    public function updateBranchProtection(string $branch, array $protectionPayload): void
    {
        try {
            $this->repoApi()->protection()->update(
                $this->getUserName(), $this->getRepositoryName(), $branch, $protectionPayload
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }
    }

    /**
     * @see https://docs.github.com/rest/repos/repos#get-a-repository
     *
     * @throws GitHubApiException
     */
    public function getRepositoryId(): int
    {
        try {
            $repository = $this->repoApi()->show(
                $this->getUserName(), $this->getRepositoryName()
            );
        } catch (Exception $exception) {
            $this->handleApiException($exception);
        }

        return RepositoryInfo::buildFromApiResponse($repository)->id;
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<mixed>
     *
     * @throws LogicException
     */
    private function paginateAll(AbstractApi $api, string $method, array $params): array
    {
        return (new ResultPager($this->client()))->fetchAll($api, $method, $params);
    }
}
