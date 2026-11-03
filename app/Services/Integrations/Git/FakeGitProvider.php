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

    /** @var array<string, array<string, mixed>> keyed "full_name:kind" (branches|commits|pulls|checks:<ref>) */
    public array $browse = [];

    /** When set, every browse/repository call throws it (rate limit and outage tests). */
    public ?GitProviderException $failWith = null;

    /** @var list<string> every browse call made, to assert caching */
    public array $calls = [];

    public function branches(int $installationId, string $fullName): array
    {
        $this->failWith && throw $this->failWith;
        $this->calls[] = "{$fullName}:branches";

        return $this->browse["{$fullName}:branches"] ?? [];
    }

    public function commits(int $installationId, string $fullName, ?string $branch = null): array
    {
        $this->failWith && throw $this->failWith;
        $this->calls[] = "{$fullName}:commits:{$branch}";

        return $this->browse["{$fullName}:commits"] ?? [];
    }

    public function pullRequests(int $installationId, string $fullName, string $state = 'all'): array
    {
        $this->failWith && throw $this->failWith;
        $this->calls[] = "{$fullName}:pulls:{$state}";

        return $this->browse["{$fullName}:pulls"] ?? [];
    }

    public function checks(int $installationId, string $fullName, string $ref): string
    {
        $this->failWith && throw $this->failWith;
        $this->calls[] = "{$fullName}:checks:{$ref}";

        return (string) ($this->browse["{$fullName}:checks:{$ref}"] ?? 'none');
    }

    /** @var list<string> "full_name:branch@sha" for every createBranch call */
    public array $created = [];

    /** @var list<string> branches that already exist, "full_name:branch" (a create is then a 422) */
    public array $existingBranches = [];

    public function createBranch(int $installationId, string $fullName, string $name, string $fromBranch): string
    {
        $this->failWith && throw $this->failWith;
        if (in_array("{$fullName}:{$name}", $this->existingBranches, true)) {
            throw new GitProviderException('exists', 422);
        }
        $this->created[] = "{$fullName}:{$name}@{$fromBranch}";

        return 'sha-of-'.$fromBranch;
    }

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
        $this->failWith && throw $this->failWith;
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
