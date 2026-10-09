<?php

namespace App\Services\Api;

use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Issues, resolves and revokes personal API tokens. The one place that knows the token format. */
class ApiTokenService
{
    /**
     * @param  array{name: string, abilities: list<string>, can_write?: bool, rate_limit?: ?int, expires_at?: mixed}  $data
     * @return array{token: ApiToken, plaintext: string}
     */
    public function issue(User $user, int $tenantId, array $data): array
    {
        $abilities = $this->normalizeAbilities($user, $data['abilities']);

        $plaintext = config('api.token_prefix').'_'.$tenantId.'_'.Str::random(40);
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'token_hash' => self::hash($plaintext),
            'token_hint' => substr($plaintext, -4),
            'abilities' => $abilities,
            'can_write' => (bool) ($data['can_write'] ?? false),
            'rate_limit' => $data['rate_limit'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** The central tenant id a plaintext token claims, or null when it is not even shaped like ours. */
    public static function tenantIdOf(string $plaintext): ?int
    {
        return preg_match('/^'.preg_quote((string) config('api.token_prefix'), '/').'_(\d+)_[A-Za-z0-9]{40}$/', $plaintext, $m)
            ? (int) $m[1]
            : null;
    }

    /** Resolves a plaintext token inside the CURRENT (already connected) tenant database. */
    public function resolve(string $plaintext): ?ApiToken
    {
        $token = ApiToken::where('token_hash', self::hash($plaintext))->first();

        return $token && $token->isUsable() ? $token : null;
    }

    public function tenantFor(string $plaintext): ?Tenant
    {
        $id = self::tenantIdOf($plaintext);
        $tenant = $id ? Tenant::find($id) : null;

        return $tenant && $tenant->isServiceable() ? $tenant : null;
    }

    public function revoke(ApiToken $token): void
    {
        if ($token->revoked_at === null) {
            $token->update(['revoked_at' => now()]);
        }
    }

    public function revokeAllFor(int $userId): int
    {
        return ApiToken::where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * A token can never be granted more than its creator holds, nor anything outside
     * the catalog of abilities a /api/v1 route is gated on.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function normalizeAbilities(User $user, array $requested): array
    {
        $catalog = array_keys((array) config('api.abilities'));
        $requested = array_values(array_unique($requested));
        $requested = in_array('*', $requested, true) ? $catalog : $requested;

        $unknown = array_diff($requested, $catalog);
        $notHeld = array_filter($requested, fn (string $slug) => ! $user->hasPermission($slug));

        if ($requested === [] || $unknown !== [] || $notHeld !== []) {
            throw ValidationException::withMessages([
                'abilities' => $requested === []
                    ? ['Choose at least one ability.']
                    : ['Unavailable abilities: '.implode(', ', [...$unknown, ...$notHeld]).'.'],
            ]);
        }

        return $requested;
    }
}
