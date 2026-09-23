# migrate-mr-to-pr
A tool to migrate merge requests from a GitLab repository to pull requests on a GitHub repository.

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Command-line option](#command-line-option)
  - [Environment variables](#environment-variables)
  - [Interactive prompt](#interactive-prompt)
- [Usage](#usage)
  - [1. Import issues](#1-import-issues)
  - [2. Import merge requests](#2-import-merge-requests)
    - [Options](#options)
    - [Branch protection](#branch-protection)
    - [Rate limiting](#rate-limiting)
- [Images and attachments](#images-and-attachments)
- [Limitations](#limitations)
- [Testing & code quality](#testing--code-quality)
- [Contributing](#contributing)
- [License](#license)
- [Credits](#credits)

## Requirements

- PHP 8.3+
- Composer

## Installation

```bash
git clone https://github.com/Maxcastel/migrate-gitlab-mr-to-github-pr.git
cd migrate-gitlab-mr-to-github-pr
composer install
```

## Configuration
Each value can be set via a command-line option, an environment variable
(`.env.local`), or an interactive prompt if neither is set.

### Command-line option

```bash
php bin/console import-mr \
  --gitLabToken=<Your GitLab token> \
  --gitLabProjectId=<Your source GitLab project ID> \
  --gitLabUser=<Your GitLab user/group> \
  --gitLabRepositoryName=<Your source GitLab repository name> \
  --gitHubToken=<Your GitHub token> \
  --gitHubUserName=<Your GitHub user name> \
  --gitHubRepositoryName=<Your destination GitHub repository name>
```

### Environment variables

Copy the example file, then fill it in:

```bash
cp .env.local.example .env.local
```

```dotenv
# GitLab (source)
GITLAB_TOKEN=
GITLAB_PROJECT_ID=
GITLAB_USER=
GITLAB_REPOSITORY_NAME=

# GitHub (destination)
GITHUB_TOKEN=
GITHUB_USERNAME=
GITHUB_REPOSITORY_NAME=
GITHUB_ASSIGNEE=
```

### Interactive prompt

Missing values are asked when the command runs:

```console
$ php bin/console import-mr
gitLabToken? <Your GitLab token>
gitLabProjectId? <Your source GitLab project ID>
```

## Usage

> [!WARNING]
> **Import the issues (`bin/console import`) before the merge requests (`bin/console import-mr`).**
>
> On GitLab, an issue is referenced with a `#` (`#10`) and a merge request with a `!`
(`!10`), while on GitHub both are referenced with a `#`.
>
> A merge request linking to the issue `#10` therefore becomes a pull request linking to
whatever holds the number 10 on GitHub, so import the issues first to keep those links on
the issues.

### 1. Import issues

```bash
php bin/console import
```

Imports GitLab issues as GitHub issues:

- **Run it before `import-mr`**: the issues take the first numbers on GitHub, so the references carried over from GitLab keep pointing at them.
- **Already imported issues are skipped**: the command can safely be re-run.

See all options with `php bin/console import --help`.

### 2. Import merge requests

```bash
php bin/console import-mr
```

Imports GitLab merge requests as GitHub pull requests:

1. **Syncs the branches**: clones the GitLab repository and pushes its branches to GitHub.
2. **Recreates each merge request** with its original merge commits, authors and dates, so the GitHub history mirrors GitLab's.

See all options with `php bin/console import-mr --help`.

#### Options

| Option | Description |
| --- | --- |
| `--gitLabToken` | GitLab token |
| `--gitLabProjectId` | GitLab project ID |
| `--gitLabUser` | GitLab user or group that owns the repository (e.g. `user123`) |
| `--gitLabRepositoryName` | GitLab repository name, without owner or `.git` (e.g. `project-abc`) |
| `--gitHubToken` | GitHub token |
| `--gitHubUserName` | GitHub user name |
| `--gitHubRepositoryName` | GitHub repository name |
| `--limit=N` | Import at most N new merge requests (already imported ones don't count) |
| `--dry-run` | Show what would be imported, without changing anything |
| `--delay=N` | Wait N seconds between two merge requests (default `1`, decimals allowed, `0` to disable) |
| `--skipSslCertificateVerification` | Disable TLS verification (for self-hosted instances with self-signed certificates) |
| `--skipTargetBranchProtection` | Don't protect the target branch at the end if it had no protection |

#### Branch protection

The import force-pushes the target branch, so its protection is handled automatically:

- **Protected branch**: the protection is removed during the import, then restored.
- **Unprotected branch**: a basic protection (no force-push, no deletion) is added at the end. Use `--skipTargetBranchProtection` to skip this.

#### Rate limiting

Each merge request sends several write requests to GitHub, which can hit its [secondary rate limit](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api#about-secondary-rate-limits). GitHub recommends at least 1 second between write requests. To handle this:

- rate-limited requests are **retried automatically**;
- the import **pauses 1 second** between two merge requests (change it with `--delay`).

## Images and attachments

GitLab links attached files with a relative path (`/uploads/<secret>/<name>`), which doesn't work on GitHub. So, in issues and merge requests:

- each image and video is downloaded from GitLab and uploaded to GitHub;
- GitLab image sizes (`![](…){width=617 height=600}`) become an `<img>` tag, the only way to size an image on GitHub. Unsupported sizes (e.g. percentages) are dropped.

## Limitations

- **No de-duplication**: running `import-mr` again creates duplicate pull requests.
- **GitLab usernames are not mapped to GitHub**: pull requests that had an assignee on GitLab are assigned to `GITHUB_ASSIGNEE` (or to the authenticated user if it's empty).

## Testing & code quality

```bash
php vendor/bin/phpunit
php vendor/bin/infection
php vendor/bin/phpstan
php vendor/bin/php-cs-fixer fix
php vendor/bin/rector
```

## Contributing

Contributions are welcome! If you change the code for your needs, feel free to share it in a pull request.

## License

[GNU General Public License v3](LICENSE.md)

## Credits

Inspired by [migrate-issues-gitlab-to-github](https://github.com/tin-cat/migrate-issues-gitlab-to-github), thanks to [@tin-cat](https://github.com/tin-cat)! To migrate only issues, go to that repository.
