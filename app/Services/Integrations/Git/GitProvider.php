<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

/**
 * The seam between the domain and a Git host. GitHub is the only implementation; a GitLab or
 * Bitbucket provider would implement this without touching the services above it. Every method
 * speaks in plain arrays shaped like the GitHub payloads the domain already consumes.
 */
interface GitProvider
{
    public function name(): string;

    /** Where the browser is sent to install the app; `$state` comes back on the setup redirect. */
    public function installUrl(string $state): string;

    /** @return array{id: int, account: array{login: string, type: string}, suspended_at: ?string, permissions: array<string, string>} */
    public function installation(int $installationId): array;

    /** Proves the person who just installed can see this installation (their OAuth `code`). */
    public function userCanAccessInstallation(string $code, int $installationId): bool;

    /**
     * One page of repositories the installation may see.
     *
     * @return array{repositories: list<array<string, mixed>>, next_page: ?int}
     */
    public function installationRepositories(int $installationId, int $page = 1): array;

    /** @return list<array{name: string, sha: string, protected: bool}> */
    public function branches(int $installationId, string $fullName): array;

    /** @return list<array{sha: string, message: string, author_login: ?string, author_name: ?string, html_url: ?string, committed_at: ?string}> */
    public function commits(int $installationId, string $fullName, ?string $branch = null): array;

    /** @return list<array{number: int, title: string, state: string, draft: bool, author_login: ?string, html_url: ?string, head_branch: ?string, base_branch: ?string, merged_at: ?string, updated_at: ?string}> */
    public function pullRequests(int $installationId, string $fullName, string $state = 'all'): array;

    /** Rolled-up check state of a commit/branch: none|pending|success|failure|neutral. */
    public function checks(int $installationId, string $fullName, string $ref): string;

    /**
     * Creates `refs/heads/{name}` pointing at the tip of `$fromBranch`; returns the commit sha it points at.
     *
     * @throws GitProviderException 404 base branch/permission, 409 empty repository, 422 the branch already exists
     */
    public function createBranch(int $installationId, string $fullName, string $name, string $fromBranch): string;

    /** Short-lived token for acting as the installation; cached encrypted until shortly before it expires. */
    public function installationToken(int $installationId): string;
}
