<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ImportException;
use App\Service\AttachmentMigrator;
use App\Service\GitHubService;
use App\Service\GitLabService;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class AttachmentMigratorTest extends TestCase
{
    private const SECRET = '6fbaec24b893e88102ca3778336b36c3';

    private const OTHER_SECRET = '4af23482d189c6eb0987c8679ef2ff94';

    private const ISSUE_URL = 'https://gitlab.com/group/project/-/issues/42';

    private const ATTACHMENT_URL = 'https://github.com/user-attachments/assets/bcf3a3ca-a300-45a1-b291-7d25174d12fe';

    private GitLabService&Stub $gitLabService;

    private GitHubService&Stub $gitHubService;

    #[Override]
    protected function setUp(): void
    {
        $this->gitLabService = self::createStub(GitLabService::class);
        $this->gitHubService = self::createStub(GitHubService::class);
    }

    private function migrator(): AttachmentMigrator
    {
        return new AttachmentMigrator($this->gitLabService, $this->gitHubService);
    }

    private function gitLabServiceMock(): GitLabService&MockObject
    {
        $mock = $this->createMock(GitLabService::class);
        $this->gitLabService = $mock;

        return $mock;
    }

    private function gitHubServiceMock(): GitHubService&MockObject
    {
        $mock = $this->createMock(GitHubService::class);
        $this->gitHubService = $mock;

        return $mock;
    }

    /**
     * @throws LogicException
     */
    public function testLeavesADescriptionWithoutAnyUploadUntouched(): void
    {
        $this->gitLabServiceMock()->expects(self::never())->method('downloadUpload');
        $this->gitHubServiceMock()->expects(self::never())->method('uploadAttachment');

        $description = "Steps to reproduce\n\nSee https://example.com/page and [the spec](../docs/spec.md).";
        $migrator = $this->migrator();

        self::assertSame($description, $migrator->migrateDescription($description, 42, self::ISSUE_URL));
        self::assertSame([], $migrator->warnings);
    }

    /**
     * @throws LogicException
     */
    public function testReplacesAGitLabUploadWithTheGitHubAttachmentItUploaded(): void
    {
        $this->gitLabServiceMock()->expects(self::once())->method('downloadUpload')
            ->with(42, self::SECRET, 'screenshot.png')
            ->willReturn('PNG-BYTES');
        $this->gitHubServiceMock()->expects(self::once())->method('uploadAttachment')
            ->with('screenshot.png', 'image/png', 'PNG-BYTES')
            ->willReturn(self::ATTACHMENT_URL);

        $migrator = $this->migrator();

        self::assertSame(
            '![screenshot]('.self::ATTACHMENT_URL.')',
            $migrator->migrateDescription('![screenshot](/uploads/'.self::SECRET.'/screenshot.png)', 42, self::ISSUE_URL)
        );
        self::assertSame([], $migrator->warnings);
    }

    /**
     * @throws LogicException
     */
    public function testHandlesTheAccentedFileNamesGitLabWritesForScreenshots(): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('JPG-BYTES');
        $this->gitHubServiceMock()->expects(self::once())->method('uploadAttachment')
            ->with('Capture_d_écran_2025-07-27_025220.jpg', 'image/jpeg', 'JPG-BYTES')
            ->willReturn(self::ATTACHMENT_URL);

        $description = '![Capture_d_écran_2025-07-27_025220](/uploads/'.self::SECRET.'/Capture_d_écran_2025-07-27_025220.jpg)';

        self::assertSame(
            '![Capture_d_écran_2025-07-27_025220]('.self::ATTACHMENT_URL.')',
            $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL)
        );
    }

    /**
     * @throws LogicException
     */
    public function testRewritesEveryUploadOfADescription(): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubServiceMock()->expects(self::exactly(2))->method('uploadAttachment')
            ->willReturnOnConsecutiveCalls(
                'https://github.com/user-attachments/assets/first',
                'https://github.com/user-attachments/assets/second'
            );

        $description = '![one](/uploads/'.self::SECRET."/one.png)\n".'![two](/uploads/'.self::OTHER_SECRET.'/two.png)';

        self::assertSame(
            "![one](https://github.com/user-attachments/assets/first)\n![two](https://github.com/user-attachments/assets/second)",
            $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL)
        );
    }

    /**
     * @throws LogicException
     */
    public function testUploadsAFileUsedTwiceOnlyOnce(): void
    {
        $this->gitLabServiceMock()->expects(self::once())->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubServiceMock()->expects(self::once())->method('uploadAttachment')->willReturn(self::ATTACHMENT_URL);

        $description = '![top](/uploads/'.self::SECRET."/same.png)\n".'![again](/uploads/'.self::SECRET.'/same.png)';

        self::assertSame(
            '![top]('.self::ATTACHMENT_URL.")\n".'![again]('.self::ATTACHMENT_URL.')',
            $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL)
        );
    }

    /**
     * @throws LogicException
     */
    public function testUploadsAFileUsedByTwoIssuesOnlyOnce(): void
    {
        $this->gitLabServiceMock()->expects(self::once())->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubServiceMock()->expects(self::once())->method('uploadAttachment')->willReturn(self::ATTACHMENT_URL);

        $description = '![shared](/uploads/'.self::SECRET.'/shared.png)';
        $migrator = $this->migrator();

        $migrator->migrateDescription($description, 42, self::ISSUE_URL);

        self::assertSame(
            '![shared]('.self::ATTACHMENT_URL.')',
            $migrator->migrateDescription($description, 42, 'https://gitlab.com/group/project/-/issues/43')
        );
    }

    /**
     * @throws LogicException
     */
    public function testRewritesAnUploadWrittenAsAnAbsoluteGitLabUrl(): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubService->method('uploadAttachment')->willReturn(self::ATTACHMENT_URL);

        $description = '![shot](https://gitlab.com/group/project/uploads/'.self::SECRET.'/shot.png)';

        self::assertSame('![shot]('.self::ATTACHMENT_URL.')', $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL));
    }

    /**
     * @throws LogicException
     */
    public function testRewritesAnUploadWrittenAsAnImgTag(): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubService->method('uploadAttachment')->willReturn(self::ATTACHMENT_URL);

        $description = '<img src="/uploads/'.self::SECRET.'/shot.png" width="448" />';

        self::assertSame(
            '<img src="'.self::ATTACHMENT_URL.'" width="448" />',
            $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL)
        );
    }

    /**
     * @throws LogicException
     */
    public function testAsksBothApisForTheDecodedFileName(): void
    {
        $this->gitLabServiceMock()->expects(self::once())->method('downloadUpload')
            ->with(42, self::SECRET, 'my screenshot.png')
            ->willReturn('BYTES');
        $this->gitHubServiceMock()->expects(self::once())->method('uploadAttachment')
            ->with('my screenshot.png', 'image/png', 'BYTES')
            ->willReturn(self::ATTACHMENT_URL);

        self::assertSame(
            '![shot]('.self::ATTACHMENT_URL.')',
            $this->migrator()->migrateDescription('![shot](/uploads/'.self::SECRET.'/my%20screenshot.png)', 42, self::ISSUE_URL)
        );
    }

    /**
     * @throws LogicException
     */
    public function testDisplaysOneLinePerFileLeftBehind(): void
    {
        $this->gitLabService->method('downloadUpload')->willThrowException(new ImportException('404 Not Found'));

        $migrator = $this->migrator();
        $migrator->migrateDescription('![shot](/uploads/'.self::SECRET.'/shot.png)', 42, self::ISSUE_URL);
        $migrator->migrateDescription('[doc](/uploads/'.self::OTHER_SECRET.'/report.pdf)', 42, self::ISSUE_URL);

        $output = new BufferedOutput();
        $migrator->displayWarnings($output);
        $rendered = $output->fetch();

        self::assertStringContainsString('Attachments that could not be uploaded to GitHub:', $rendered);
        self::assertStringContainsString(" - 'shot.png' could not be attached to GitHub (404 Not Found)", $rendered);
        self::assertStringContainsString(" - 'report.pdf' is not an image or a video", $rendered);
    }

    /**
     * @throws LogicException
     */
    public function testDisplaysNothingWhenEveryFileWentThrough(): void
    {
        $output = new BufferedOutput();

        $this->migrator()->displayWarnings($output);

        self::assertSame('', $output->fetch(), 'a run without any warning must stay silent');
    }

    /**
     * @throws LogicException
     */
    public function testTurnsTheImageAttributesOfGitLabIntoAnImgTag(): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubService->method('uploadAttachment')->willReturn(self::ATTACHMENT_URL);

        $description = '![Capture](/uploads/'.self::SECRET.'/shot.png){width=617 height=600}';

        self::assertSame(
            '<img width="617" height="600" alt="Capture" src="'.self::ATTACHMENT_URL.'" />',
            $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL)
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function imageAttributesProvider(): iterable
    {
        yield 'both sizes' => ['{width=617 height=600}', ' width="617" height="600"'];
        yield 'width only' => ['{width=617}', ' width="617"'];
        yield 'height only' => ['{height=600}', ' height="600"'];
        yield 'sizes in the other order' => ['{height=600 width=617}', ' width="617" height="600"'];
        yield 'quoted values' => ['{width="617" height="600"}', ' width="617" height="600"'];
        yield 'pixel suffix' => ['{width=617px}', ' width="617"'];
        yield 'spaces around the equals sign' => ['{width = 617}', ' width="617"'];
        yield 'uppercase attribute' => ['{WIDTH=617}', ' width="617"'];
    }

    /**
     * @throws LogicException
     */
    #[DataProvider('imageAttributesProvider')]
    public function testReadsEveryShapeOfSizeGitLabWrites(string $attributes, string $expectedSizes): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubService->method('uploadAttachment')->willReturn(self::ATTACHMENT_URL);

        $description = '![Capture](/uploads/'.self::SECRET.'/shot.png)'.$attributes;

        self::assertSame(
            '<img'.$expectedSizes.' alt="Capture" src="'.self::ATTACHMENT_URL.'" />',
            $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableImageAttributesProvider(): iterable
    {
        yield 'percentage, which an <img> tag cannot express' => ['{width=75%}'];
        yield 'a class, which GitHub strips anyway' => ['{.shadow}'];
        yield 'nothing between the braces' => ['{}'];
    }

    /**
     * @throws LogicException
     */
    #[DataProvider('unusableImageAttributesProvider')]
    public function testDropsTheAttributesItCannotTranslate(string $attributes): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubService->method('uploadAttachment')->willReturn(self::ATTACHMENT_URL);

        $description = '![Capture](/uploads/'.self::SECRET.'/shot.png)'.$attributes;

        self::assertSame(
            '![Capture]('.self::ATTACHMENT_URL.')',
            $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL)
        );
    }

    /**
     * @throws LogicException
     */
    public function testEscapesTheAltTextItPutsInTheTag(): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubService->method('uploadAttachment')->willReturn(self::ATTACHMENT_URL);

        $description = '![a "quoted" shot](/uploads/'.self::SECRET.'/shot.png){width=617}';

        self::assertSame(
            '<img width="617" alt="a &quot;quoted&quot; shot" src="'.self::ATTACHMENT_URL.'" />',
            $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL)
        );
    }

    /**
     * @throws LogicException
     */
    public function testSizesAnImageThatWasNeverUploadedToGitLab(): void
    {
        $this->gitLabServiceMock()->expects(self::never())->method('downloadUpload');

        self::assertSame(
            '<img width="200" height="80" alt="Logo" src="https://example.com/logo.png" />',
            $this->migrator()->migrateDescription('![Logo](https://example.com/logo.png){width=200 height=80}', 42, self::ISSUE_URL)
        );
    }

    /**
     * @throws LogicException
     */
    public function testSizesEveryImageOfADescription(): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubService->method('uploadAttachment')->willReturnOnConsecutiveCalls(
            'https://github.com/user-attachments/assets/first',
            'https://github.com/user-attachments/assets/second'
        );

        $description = '![one](/uploads/'.self::SECRET."/one.png){width=10}\n"
            .'![two](/uploads/'.self::OTHER_SECRET.'/two.png){height=20}';

        self::assertSame(
            '<img width="10" alt="one" src="https://github.com/user-attachments/assets/first" />'."\n"
            .'<img height="20" alt="two" src="https://github.com/user-attachments/assets/second" />',
            $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL)
        );
    }

    /**
     * @throws LogicException
     */
    public function testLeavesBracesThatFollowSomethingElseAlone(): void
    {
        $this->gitLabServiceMock()->expects(self::never())->method('downloadUpload');

        $description = 'The payload is [documented here](https://example.com/api) {"key": "value"}.';

        self::assertSame($description, $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedFileProvider(): iterable
    {
        yield 'png' => ['shot.png', 'image/png'];
        yield 'jpg' => ['shot.jpg', 'image/jpeg'];
        yield 'jpeg' => ['shot.jpeg', 'image/jpeg'];
        yield 'gif' => ['shot.gif', 'image/gif'];
        yield 'webp' => ['shot.webp', 'image/webp'];
        yield 'svg' => ['drawing.svg', 'image/svg+xml'];
        yield 'mp4' => ['capture.mp4', 'video/mp4'];
        yield 'webm' => ['capture.webm', 'video/webm'];
        yield 'mov' => ['capture.mov', 'video/quicktime'];
        yield 'uppercase extension' => ['SHOT.PNG', 'image/png'];
    }

    /**
     * @throws LogicException
     */
    #[DataProvider('acceptedFileProvider')]
    public function testDeclaresTheContentTypeGitHubExpectsForTheExtension(string $fileName, string $expectedContentType): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubServiceMock()->expects(self::once())->method('uploadAttachment')
            ->with($fileName, $expectedContentType, 'BYTES')
            ->willReturn(self::ATTACHMENT_URL);

        self::assertSame(
            '![file]('.self::ATTACHMENT_URL.')',
            $this->migrator()->migrateDescription('![file](/uploads/'.self::SECRET.'/'.$fileName.')', 42, self::ISSUE_URL)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedFileProvider(): iterable
    {
        yield 'pdf' => ['report.pdf'];
        yield 'zip' => ['logs.zip'];
        yield 'text file' => ['notes.txt'];
        yield 'no extension' => ['Dockerfile'];
    }

    /**
     * @throws LogicException
     */
    #[DataProvider('refusedFileProvider')]
    public function testLinksToGitLabTheFilesGitHubRefusesAsAttachments(string $fileName): void
    {
        $this->gitLabServiceMock()->expects(self::never())->method('downloadUpload');
        $this->gitHubServiceMock()->expects(self::never())->method('uploadAttachment');

        $migrator = $this->migrator();

        self::assertSame(
            '[file](https://gitlab.com/group/project/uploads/'.self::SECRET.'/'.$fileName.')',
            $migrator->migrateDescription('[file](/uploads/'.self::SECRET.'/'.$fileName.')', 42, self::ISSUE_URL)
        );
        self::assertSame(
            [\sprintf("'%s' is not an image or a video, GitHub refuses it as an attachment: linked to GitLab instead", $fileName)],
            $migrator->warnings
        );
    }

    /**
     * @throws LogicException
     */
    public function testLinksToGitLabWhenTheDownloadFails(): void
    {
        $this->gitLabService->method('downloadUpload')->willThrowException(new ImportException('404 Not Found'));
        $this->gitHubServiceMock()->expects(self::never())->method('uploadAttachment');

        $migrator = $this->migrator();

        self::assertSame(
            '![shot](https://gitlab.com/group/project/uploads/'.self::SECRET.'/shot.png)',
            $migrator->migrateDescription('![shot](/uploads/'.self::SECRET.'/shot.png)', 42, self::ISSUE_URL)
        );
        self::assertSame(
            ["'shot.png' could not be attached to GitHub (404 Not Found): linked to GitLab instead"],
            $migrator->warnings
        );
    }

    /**
     * @throws LogicException
     */
    public function testLinksToGitLabWhenTheUploadFails(): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubService->method('uploadAttachment')->willThrowException(new ImportException('413 Payload Too Large'));

        $migrator = $this->migrator();

        self::assertSame(
            '![shot](https://gitlab.com/group/project/uploads/'.self::SECRET.'/shot.png)',
            $migrator->migrateDescription('![shot](/uploads/'.self::SECRET.'/shot.png)', 42, self::ISSUE_URL)
        );
        self::assertSame(
            ["'shot.png' could not be attached to GitHub (413 Payload Too Large): linked to GitLab instead"],
            $migrator->warnings
        );
    }

    /**
     * @throws LogicException
     */
    public function testKeepsMigratingTheNextFileAfterAFailedUpload(): void
    {
        $this->gitLabService->method('downloadUpload')->willReturn('BYTES');
        $this->gitHubService->method('uploadAttachment')->willReturnCallback(
            static fn (string $fileName): string => 'broken.png' === $fileName
                ? throw new ImportException('413 Payload Too Large') : self::ATTACHMENT_URL
        );

        $description = '![broken](/uploads/'.self::SECRET."/broken.png)\n".'![fine](/uploads/'.self::OTHER_SECRET.'/fine.png)';
        $migrator = $this->migrator();

        self::assertSame(
            '![broken](https://gitlab.com/group/project/uploads/'.self::SECRET."/broken.png)\n".'![fine]('.self::ATTACHMENT_URL.')',
            $migrator->migrateDescription($description, 42, self::ISSUE_URL)
        );
        self::assertCount(1, $migrator->warnings);
    }

    /**
     * @throws LogicException
     */
    public function testKeepsTheRelativePathWhenTheIssueUrlHidesTheProject(): void
    {
        $this->gitLabService->method('downloadUpload')->willThrowException(new ImportException('403 Forbidden'));

        self::assertSame(
            '![shot](/uploads/'.self::SECRET.'/shot.png)',
            $this->migrator()->migrateDescription('![shot](/uploads/'.self::SECRET.'/shot.png)', 42, 'https://gitlab.com/group/project/issues/42')
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unrelatedPathProvider(): iterable
    {
        yield 'secret too short' => ['![x](/uploads/6fbaec24b893e88102ca3778336b36/x.png)'];
        yield 'secret not hexadecimal' => ['![x](/uploads/zzzzzz24b893e88102ca3778336b36c3/x.png)'];
        yield 'no file name' => ['![x](/uploads/'.self::SECRET.'/)'];
        yield 'uploads directory of the repository' => ['[x](/docs/uploads/readme.md)'];
    }

    /**
     * @throws LogicException
     */
    #[DataProvider('unrelatedPathProvider')]
    public function testLeavesAloneWhatIsNotAnUploadLink(string $description): void
    {
        $this->gitLabServiceMock()->expects(self::never())->method('downloadUpload');

        self::assertSame($description, $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL));
    }

    /**
     * @throws LogicException
     */
    public function testKeepsADescriptionThatIsNotValidUtf8(): void
    {
        $this->gitLabServiceMock()->expects(self::never())->method('downloadUpload');

        $description = '![x](/uploads/'.self::SECRET."/\xFF.png)";

        self::assertSame($description, $this->migrator()->migrateDescription($description, 42, self::ISSUE_URL));
    }
}
