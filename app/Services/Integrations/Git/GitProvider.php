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

    /** Short-lived token for acting as the installation; cached encrypted until shortly before it expires. */
    public function installationToken(int $installationId): string;
}
