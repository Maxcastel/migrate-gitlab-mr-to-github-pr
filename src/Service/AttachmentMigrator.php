<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ImportException;
use LogicException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Turns the GitLab upload links of a description into GitHub attachment links.
 *
 * GitLab stores a file attached to an issue or a merge request as a path relative to the
 * project (`/uploads/<secret>/<file name>`), which GitHub resolves against its own domain:
 * the image is broken on the imported issue. Each file is therefore downloaded from GitLab
 * and uploaded to GitHub, which answers the only kind of URL a private repository renders:
 * https://github.com/user-attachments/assets/<uuid>.
 *
 * A file GitHub refuses to store is linked to GitLab with an absolute URL instead, so that
 * it at least stays reachable to whoever has access to the GitLab project.
 */
class AttachmentMigrator
{
    private const CONTENT_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
    ];

    private const UPLOAD_PATTERN = '#(?:https?://[^\s"\'<>()\[\]]*?)?/uploads/([0-9a-f]{32})/([^\s"\'<>()\[\]]+)#u';

    /**
     * Matches the image attributes GitLab writes after a link (`![alt](url){width=617 height=600}`).
     */
    private const IMAGE_ATTRIBUTES_PATTERN = '#!\[([^\]]*)\]\(([^)\s]+)\)\{([^}\n]*)\}#u';

    /**
     * @var array<string, string>
     */
    private array $migratedUrls = [];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    public function __construct(
        private GitLabService $gitLabService,
        private GitHubService $gitHubService,
    ) {}

    /**
     * @throws LogicException
     */
    public function migrateDescription(string $description, int $gitLabProjectId, string $gitLabUrl): string
    {
        $projectUrl = $this->extractProjectUrl($gitLabUrl);

        $migratedDescription = preg_replace_callback(
            self::UPLOAD_PATTERN,
            fn (array $matches): string => $this->migrateUpload($gitLabProjectId, $matches[1], $matches[2], $projectUrl),
            $description
        );

        $migratedDescription ??= $description;

        $sizedDescription = preg_replace_callback(
            self::IMAGE_ATTRIBUTES_PATTERN,
            fn (array $matches): string => $this->applyImageAttributes($matches[1], $matches[2], $matches[3]),
            $migratedDescription
        );

        return $sizedDescription ?? $migratedDescription;
    }

    private function applyImageAttributes(string $alt, string $url, string $attributes): string
    {
        $sizes = '';
        foreach (['width', 'height'] as $attribute) {
            if (1 === preg_match('#(?<![\w-])'.$attribute.'\s*=\s*"?(\d+)(?:px)?"?(?![\d%])#i', $attributes, $matches)) {
                $sizes .= \sprintf(' %s="%s"', $attribute, $matches[1]);
            }
        }

        if ('' === $sizes) {
            return \sprintf('![%s](%s)', $alt, $url);
        }

        return \sprintf('<img%s alt="%s" src="%s" />', $sizes, htmlspecialchars($alt, \ENT_QUOTES), $url);
    }

    /**
     * @return string the URL replacing the GitLab link
     *
     * @throws LogicException
     */
    private function migrateUpload(int $gitLabProjectId, string $secret, string $linkedFileName, string $projectUrl): string
    {
        $uploadPath = '/uploads/'.$secret.'/'.$linkedFileName;

        if (isset($this->migratedUrls[$uploadPath])) {
            return $this->migratedUrls[$uploadPath];
        }

        $fallbackUrl = $projectUrl.$uploadPath;

        $fileName = rawurldecode($linkedFileName);
        $contentType = self::CONTENT_TYPES[$this->extractExtension($fileName)] ?? null;

        if (null === $contentType) {
            $this->warnings[] = \sprintf("'%s' is not an image or a video, GitHub refuses it as an attachment: linked to GitLab instead", $fileName);

            return $fallbackUrl;
        }

        try {
            $contents = $this->gitLabService->downloadUpload($gitLabProjectId, $secret, $fileName);
            $attachmentUrl = $this->gitHubService->uploadAttachment($fileName, $contentType, $contents);
        } catch (ImportException $importException) {
            $this->warnings[] = \sprintf("'%s' could not be attached to GitHub (%s): linked to GitLab instead", $fileName, $importException->getMessage());

            return $fallbackUrl;
        }

        return $this->migratedUrls[$uploadPath] = $attachmentUrl;
    }

    public function displayWarnings(OutputInterface $output): void
    {
        if ([] === $this->warnings) {
            return;
        }

        $output->writeln('');
        $output->writeln('Attachments that could not be uploaded to GitHub:');
        foreach ($this->warnings as $warning) {
            $output->writeln(' - '.$warning);
        }
    }

    /**
     * Turns https://gitlab.com/group/project/-/issues/42 into https://gitlab.com/group/project.
     */
    private function extractProjectUrl(string $gitLabUrl): string
    {
        $separatorPosition = strpos($gitLabUrl, '/-/');

        return false === $separatorPosition ? '' : substr($gitLabUrl, 0, $separatorPosition);
    }

    private function extractExtension(string $fileName): string
    {
        $dotPosition = strrpos($fileName, '.');

        return false === $dotPosition ? '' : strtolower(substr($fileName, $dotPosition + 1));
    }
}
