<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\ImportException;
use UnhandledMatchError;

class Issue
{
    /**
     * @param list<string> $labels
     * @param list<string> $assignees
     */
    public function __construct(
        public int $gitLabId = 0,
        public int $gitLabIid = 0,
        public int $gitLabProjectId = 0,
        public string $gitLabUrl = '',
        public string $title = '',
        public string $description = '',
        public IssueState $state = IssueState::Open,
        public int $createdAt = 0,
        public array $labels = [],
        public array $assignees = [],
    ) {}

    /**
     * @param array<mixed> $data
     *
     * @throws ImportException
     * @throws UnhandledMatchError
     */
    public static function buildFromGitLabApiResponse(array $data): self
    {
        $gitLabId = $data['id'] ?? null;
        $gitLabIid = $data['iid'] ?? null;
        $gitLabProjectId = $data['project_id'] ?? null;

        if (!\is_int($gitLabId) || !\is_int($gitLabIid) || !\is_int($gitLabProjectId)) {
            throw new ImportException('Malformed GitLab issue payload: "id", "iid" and "project_id" must be integers');
        }

        $gitLabUrl = $data['web_url'] ?? null;
        $title = $data['title'] ?? null;
        $createdAt = $data['created_at'] ?? null;
        if (!\is_string($gitLabUrl) || !\is_string($title) || !\is_string($createdAt)) {
            throw new ImportException(\sprintf('Malformed GitLab issue payload for issue #%d: "web_url", "title" and "created_at" must be strings', $gitLabIid));
        }

        $description = $data['description'] ?? null;

        $state = $data['state'] ?? null;

        $dataAssignees = $data['assignees'] ?? null;
        $assignees = [];
        if (\is_array($dataAssignees)) {
            foreach ($dataAssignees as $assignee) {
                $userName = \is_array($assignee) ? ($assignee['username'] ?? null) : null;
                if (\is_string($userName)) {
                    $assignees[] = $userName;
                }
            }
        }

        return
            new self(
                gitLabId: $gitLabId,
                gitLabIid: $gitLabIid,
                gitLabProjectId: $gitLabProjectId,
                gitLabUrl: $gitLabUrl,
                title: $title,
                description: \is_string($description) ? $description : '',
                state: match ($state) {
                    'opened' => IssueState::Open,
                    'closed' => IssueState::Closed,
                    default => throw new UnhandledMatchError(\sprintf('Unhandled GitLab issue state "%s"', \is_scalar($state) ? $state : get_debug_type($state))),
                },
                createdAt: strtotime($createdAt) ?: 0,
                assignees: $assignees
            );
    }
}
