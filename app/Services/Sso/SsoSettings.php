<?php

namespace App\Services\Sso;

use App\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

/** A tenant's single-sign-on configuration, kept in `tenants.settings.sso` with the client secret encrypted. */
class SsoSettings
{
    public const DEFAULTS = ['enabled' => false, 'enforce' => false, 'jit' => false, 'issuer' => '', 'client_id' => '', 'allowed_domains' => [], 'default_role' => 'viewer'];

    /** @return array<string, mixed> */
    public function get(Tenant $tenant): array
    {
        return array_merge(self::DEFAULTS, (array) ($tenant->settings['sso'] ?? []));
    }

    public function secret(Tenant $tenant): ?string
    {
        $enc = $tenant->settings['sso']['client_secret'] ?? null;

        return $enc ? Crypt::decryptString($enc) : null;
    }

    public function isUsable(Tenant $tenant): bool
    {
        $s = $this->get($tenant);

        return $s['enabled'] && $s['issuer'] && $s['client_id'] && $this->secret($tenant);
    }

    /** @param array<string, mixed> $data  `client_secret` blank/absent keeps the stored one */
    public function save(Tenant $tenant, array $data): void
    {
        $settings = (array) ($tenant->settings ?? []);
        $current = (array) ($settings['sso'] ?? []);

        $next = array_merge($current, array_intersect_key($data, self::DEFAULTS));
        if (! empty($data['client_secret'])) {
            $next['client_secret'] = Crypt::encryptString($data['client_secret']);
        }

        $settings['sso'] = $next;
        $tenant->settings = $settings;
        $tenant->save();
    }

    /** What the admin UI may see: never the secret itself. */
    public function present(Tenant $tenant): array
    {
        return Arr::except($this->get($tenant), ['client_secret']) + ['has_secret' => (bool) ($tenant->settings['sso']['client_secret'] ?? false)];
    }
}
