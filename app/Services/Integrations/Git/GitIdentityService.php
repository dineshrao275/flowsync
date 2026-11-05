<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Models\GitIdentity;
use App\Models\User;
use App\Services\PlatformAudit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Admin-managed map from a GitHub login to a tenant user. Smart commits act as the mapped user and only as them:
 * there is no guessing from names or e-mail addresses, because a commit author is whatever `git config` said.
 */
class GitIdentityService
{
    public function __construct(private readonly PlatformAudit $audit, private readonly TenantContext $tenant) {}

    /** @return Collection<int, GitIdentity> */
    public function list(): Collection
    {
        return GitIdentity::query()->orderBy('login')->get();
    }

    public function map(int $userId, string $login, ?User $actor = null, ?Request $request = null): GitIdentity
    {
        $login = strtolower(trim(ltrim($login, '@')));
        $taken = GitIdentity::query()->where('provider', 'github')->where('login', $login)->where('user_id', '!=', $userId)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['login' => __('That GitHub login is already mapped to another user.')]);
        }

        $identity = GitIdentity::query()->updateOrCreate(
            ['user_id' => $userId, 'provider' => 'github'],
            ['login' => $login, 'linked_at' => now()],
        );
        $this->record($request, 'git.identity_mapped', $identity, $actor);

        return $identity;
    }

    public function unmap(GitIdentity $identity, ?User $actor = null, ?Request $request = null): void
    {
        $identity->delete();
        $this->record($request, 'git.identity_unmapped', $identity, $actor);
    }

    /** The tenant user a commit author or `#assign @login` refers to, or null when nobody is mapped. */
    public function userFor(?string $login): ?User
    {
        if ($login === null || $login === '') {
            return null;
        }
        $identity = GitIdentity::query()->where('provider', 'github')->where('login', strtolower($login))->first();

        return $identity === null ? null : User::query()->find($identity->user_id);
    }

    private function record(?Request $request, string $action, GitIdentity $identity, ?User $actor): void
    {
        $this->audit->record($request, $action, 'git_identity', $identity->id, [
            'tenant_id' => $this->tenant->currentId(), 'user_id' => $identity->user_id, 'login' => $identity->login,
            'actor_user_id' => $actor?->id, 'actor_name' => $actor?->name,
        ]);
    }
}
