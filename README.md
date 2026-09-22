# migrate-mr-to-pr
A tool to migrate merge requests from a GitLab repository to pull requests on a GitHub repository.

## Requirements
- PHP 8.3+
- Composer

## Installation
```bash
git clone https://github.com/Maxcastel/migrate-mr-to-pr.git
cd migrate-mr-to-pr
composer install
```

## Configuration
Each value can be set via a command-line option, an environment variable
(`.env.local`), or an interactive prompt if neither is set.

**Command-line option**
```bash
php bin/console import-mr \
  --gitLabToken=<Your GitLab token> \
  --gitLabProjectId=<Your source GitLab project id> \
  --gitLabUser=<Your GitLab user/group> \
  --gitLabRepositoryName=<Your source GitLab repository name> \
  --gitHubToken=<Your GitHub token> \
  --gitHubUserName=<Your GitHub user name> \
  --gitHubRepositoryName=<Your destination GitHub repository name>
```

**Environment variable**

Copy the provided `.env.local.example` file and fill in your configuration parameters:

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

**Interactive prompt**

If a value is neither passed as an option nor set in `.env.local`, you're
asked for it when running the command:
```bash
$ php bin/console import-mr
gitLabToken? <Your GitLab token>
gitLabProjectId? <Your source GitLab project id>
```

## Import order: issues first, merge requests second

> [!WARNING]
> **Run the issues import (`import`) before the merge requests import (`import-mr`), so
> that the pull requests point to issues and not to other pull requests.**

On GitLab, an issue is referenced with a `#` (`#10`) and a merge request with a `!`
(`!10`), while on GitHub both are referenced with a `#`.

A merge request linking to the issue `#10` therefore becomes a pull request linking to
whatever holds the number 10 on GitHub, so import the issues first to keep those links on
the issues.

## Commands

Run them **in this order**: `import` first, then `import-mr` (see the [warning above](#import-order-issues-first-merge-requests-second)).

### 1. Import issues : `import`
```bash
php bin/console import
```
Imports the GitLab issues as GitHub issues. Run it **before** `import-mr` so the issues
take the first numbers on GitHub and the references carried over from GitLab keep pointing
at them. Already imported issues are skipped, so the command can safely be re-run.

Run `php bin/console import --help` for the full list of options.

### 2. Import merge requests : `import-mr`
```bash
php bin/console import-mr
```
Imports GitLab merge requests as GitHub pull requests. It first **synchronises
branches** (clones the GitLab repo and pushes its branches to GitHub), then
recreates each MR, preserving the original merge commits, authors and dates so
the GitHub history mirrors GitLab's.

Because the import force-pushes the target branch, an existing branch protection
is **temporarily lifted and restored** automatically. A target branch that had no
protection is given a baseline one (force-push + deletion blocked) at the end;
pass `--skipTargetBranchProtection` to opt out.

Run `php bin/console import-mr --help` for the full list:

| Option | Description |
| --- | --- |
| `--gitLabToken` | The GitLab token. |
| `--gitLabProjectId` | The GitLab source project Id. |
| `--gitHubToken` | The GitHub token. |
| `--gitHubUserName` | The GitHub user name. |
| `--gitHubRepositoryName` | The GitHub destination repository name. |
| `--gitLabUser` | The GitLab user/group owning the source repository (e.g. `user123`). |
| `--gitLabRepositoryName` | The GitLab source repository name, without owner or `.git` (e.g. `project-abc`). |
| `--limit=N` | Import at most N new merge requests (already-existing ones don't count). |
| `--dry-run` | Show what would be imported without making any change. |
| `--skipSslCertificateVerification` | Disable TLS verification (self-hosted instances with self-signed certs). |
| `--skipTargetBranchProtection` | Do not add branch protection to the target branch after the import when it had none. |
| `--delay=1` | Seconds to pause between each imported merge request. Fractional values allowed (e.g. `0.5`), `0` to disable. |

**Rate limiting.** Each MR triggers several GitHub write requests, which can hit
GitHub's [secondary rate limit](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api#about-secondary-rate-limits)
(GitHub recommends at least 1 s between mutating requests). Two safeguards handle
this: requests that get rate-limited are **retried automatically**, and a **`--delay` pause** (default `1` second) is inserted between
each imported MR. Fractional values are accepted (e.g. `--delay=0.5`). Set
`--delay=0` to disable the pause.

**Images and attachments.** GitLab links a file attached to an issue or a merge request as
a path relative to the project (`/uploads/<secret>/<name>`), which GitHub resolves against
its own domain: the image would be broken on the imported issue. Every image and video is
therefore downloaded from GitLab and uploaded to GitHub, which answers the
`https://github.com/user-attachments/assets/<uuid>` URL the web interface produces when a
file is dropped into an issue. The image attributes GitLab writes after a link
(`![](…){width=617 height=600}`) become an `<img>` tag, the only way GitHub sizes an image;
a size it cannot express, such as a percentage, is dropped rather than printed as text.

## Features
- Tries to be gentle with APIs to avoid triggering rate limits: auto-retry on rate-limit responses plus a configurable `--delay` between merge-request imports.
- Merge-request import preserves merge commits, authorship and dates.
- Images attached to issues and merge requests are re-uploaded to GitHub.

## Downsides / limitations
Merge-request de-duplication is not implemented, so re-running `import-mr`
will create duplicate pull requests.

## Please notice
- Imported PRs that had an assignee on GitLab are assigned to `GITHUB_ASSIGNEE` (or the authenticated user when it is empty). GitLab usernames are not mapped to GitHub usernames.

## Testing & Code Quality
```bash
php vendor/bin/phpunit
php vendor/bin/infection
php vendor/bin/phpstan
php vendor/bin/php-cs-fixer fix
php vendor/bin/rector
```

## License
GNU General Public License v3

## Contribute
Please feel free to contribute to this project.

If your needs or use case require modifying the code, don't hesitate to share your changes by opening a pull request.

## Credits
This project is inspired by [migrate-issues-gitlab-to-github](https://github.com/tin-cat/migrate-issues-gitlab-to-github), thanks to [@tin-cat](https://github.com/tin-cat)!. If you need to migrate issues, go to that repository.
