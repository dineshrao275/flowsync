<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

/** In-memory provider for tests: nothing leaves the process. Seed `$installations` and `$repositories`. */
class FakeGitProvider implements GitProvider
{
    /** @var array<int, array{id: int, account: array{login: string, type: string}, suspended_at: ?string, permissions: array<string, string>}> */
    public array $installations = [];

    /** @var array<int, list<array<string, mixed>>> installation id => repositories */
    public array $repositories = [];

    /** @var array<string, list<int>> OAuth code => installation ids that user can see */
    public array $userInstallations = [];

    public function name(): string
    {
        return 'github';
    }

    public function installUrl(string $state): string
    {
        return 'https://github.com/apps/fake/installations/new?'.http_build_query(['state' => $state]);
    }

    public function installation(int $installationId): array
    {
        return $this->installations[$installationId]
            ?? throw new GitProviderException('Not found.', 404);
    }

    public function userCanAccessInstallation(string $code, int $installationId): bool
    {
        return in_array($installationId, $this->userInstallations[$code] ?? [], true);
    }

    public function installationRepositories(int $installationId, int $page = 1): array
    {
        $all = $this->repositories[$installationId] ?? [];
        $perPage = (int) config('git.per_page');
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        return ['repositories' => $slice, 'next_page' => $page * $perPage < count($all) ? $page + 1 : null];
    }

    public function installationToken(int $installationId): string
    {
        return "fake-token-{$installationId}";
    }
}
