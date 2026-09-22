<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Issue;
use App\Entity\IssueState;
use App\Exception\ImportException;
use PHPUnit\Framework\TestCase;
use UnhandledMatchError;

final class IssueTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function baseGitLabPayload(): array
    {
        return [
            'id' => 100,
            'iid' => 42,
            'project_id' => 999,
            'web_url' => 'https://gitlab.com/test/repo/-/issues/42',
            'title' => 'Bug: thing is broken',
            'description' => 'Steps to reproduce…',
            'state' => 'opened',
            'created_at' => '2025-09-08T10:00:00.000Z',
        ];
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     */
    public function testBuildOpenedIssue(): void
    {
        $issue = Issue::buildFromGitLabApiResponse($this->baseGitLabPayload());

        self::assertSame(100, $issue->gitLabId);
        self::assertSame(42, $issue->gitLabIid);
        self::assertSame(999, $issue->gitLabProjectId);
        self::assertSame('Bug: thing is broken', $issue->title);
        self::assertSame('Steps to reproduce…', $issue->description);
        self::assertSame(IssueState::Open, $issue->state);
        self::assertSame(strtotime('2025-09-08T10:00:00.000Z'), $issue->createdAt);
        self::assertSame([], $issue->assignees);
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     */
    public function testBuildClosedIssue(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['state'] = 'closed';

        $issue = Issue::buildFromGitLabApiResponse($payload);

        self::assertSame(IssueState::Closed, $issue->state);
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     */
    public function testAssigneesAreExtractedAsUsernames(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['assignees'] = [
            ['username' => 'alice'],
            ['username' => 'bob'],
            ['id' => 3],
        ];

        $issue = Issue::buildFromGitLabApiResponse($payload);

        self::assertSame(['alice', 'bob'], $issue->assignees);
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     */
    public function testUnknownStateThrowsAndNamesTheOffendingValue(): void
    {
        $this->expectException(UnhandledMatchError::class);
        $this->expectExceptionMessage('Unhandled GitLab issue state "locked"');

        $payload = $this->baseGitLabPayload();
        $payload['state'] = 'locked';

        Issue::buildFromGitLabApiResponse($payload);
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     */
    public function testRejectsAPayloadWhoseIidIsNotAnInteger(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Malformed GitLab issue payload: "id", "iid" and "project_id" must be integers');

        $payload = $this->baseGitLabPayload();
        $payload['iid'] = '42';

        Issue::buildFromGitLabApiResponse($payload);
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     */
    public function testRejectsAPayloadWithoutACreationDateAndNamesTheIssue(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Malformed GitLab issue payload for issue #42: "web_url", "title" and "created_at" must be strings');

        $payload = $this->baseGitLabPayload();
        unset($payload['created_at']);

        Issue::buildFromGitLabApiResponse($payload);
    }

    /**
     * @throws ImportException
     * @throws UnhandledMatchError
     */
    public function testADateGitLabCannotBeReadFallsBackToNoDateAtAll(): void
    {
        $payload = $this->baseGitLabPayload();
        $payload['created_at'] = 'not a date';

        self::assertSame(0, Issue::buildFromGitLabApiResponse($payload)->createdAt);
    }

    public function testDefaultConstructorValuesArePinned(): void
    {
        $issue = new Issue();

        self::assertSame(0, $issue->gitLabId);
        self::assertSame(0, $issue->gitLabIid);
        self::assertSame(0, $issue->gitLabProjectId);
        self::assertSame('', $issue->gitLabUrl);
        self::assertSame('', $issue->title);
        self::assertSame('', $issue->description);
        self::assertSame(IssueState::Open, $issue->state, 'default state is Open, not Closed');
        self::assertSame(0, $issue->createdAt);
        self::assertSame([], $issue->labels);
        self::assertSame([], $issue->assignees);
    }
}
