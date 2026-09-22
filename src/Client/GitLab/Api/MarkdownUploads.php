<?php

declare(strict_types=1);

namespace App\Client\GitLab\Api;

use Gitlab\Api\AbstractApi;
use Http\Client\Exception;
use Psr\Http\Message\ResponseInterface;

/**
 * @see https://docs.gitlab.com/api/project_markdown_uploads/
 */
final class MarkdownUploads extends AbstractApi
{
    /**
     * @see https://docs.gitlab.com/api/project_markdown_uploads/#download-an-uploaded-file-by-secret-and-filename
     *
     * @throws Exception
     */
    public function download(int $projectId, string $secret, string $fileName): ResponseInterface
    {
        return $this->getAsResponse(
            $this->getProjectPath($projectId, 'uploads/'.self::encodePath($secret).'/'.self::encodePath($fileName))
        );
    }
}
