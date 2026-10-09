<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\FlagOverride;
use App\Support\TenantContext;

/**
 * Runtime feature flags (P8.7). Resolution order:
 *   tenant override -> global switch (must be on) -> stable rollout bucket.
 * An unknown flag is OFF (fail closed). The bucket is crc32("key:tenant") % 100,
 * so a tenant keeps its answer as the percentage widens, and tenants spread
 * evenly. Bound `scoped` in AppServiceProvider: one request / queued job.
 */
class FeatureFlags
{
    /** @var array<string, FeatureFlag>|null */
    private ?array $flags = null;

    /** @var array<int, array<int, bool>>|null flag id => tenant id => enabled */
    private ?array $overrides = null;

    public function enabled(string $key, ?int $tenantId = null): bool
    {
        $tenantId ??= app(TenantContext::class)->currentId();
        $flag = $this->flags()[$key] ?? null;

        if ($flag === null) {
            return false;
        }

        if ($tenantId !== null && isset($this->overrides()[$flag->id][$tenantId])) {
            return $this->overrides()[$flag->id][$tenantId];
        }

        if (! $flag->enabled) {
            return false;
        }

        if ($flag->rollout_percent >= 100) {
            return true;
        }

        return $tenantId !== null && self::bucket($key, $tenantId) < $flag->rollout_percent;
    }

    /** @return array<string, bool> every known flag's verdict for the tenant (SPA mirror). */
    public function allFor(?int $tenantId): array
    {
        $out = [];
        foreach (array_keys($this->flags()) as $key) {
            $out[$key] = $this->enabled($key, $tenantId);
        }

        return $out;
    }

    public static function bucket(string $key, int $tenantId): int
    {
        return crc32($key.':'.$tenantId) % 100;
    }

    /** Drop the memo after an admin write so the same request reads fresh state. */
    public function forget(): void
    {
        $this->flags = $this->overrides = null;
    }

    /** @return array<string, FeatureFlag> */
    private function flags(): array
    {
        return $this->flags ??= FeatureFlag::query()->get()->keyBy('key')->all();
    }

    /** @return array<int, array<int, bool>> */
    private function overrides(): array
    {
        if ($this->overrides === null) {
            $this->overrides = [];
            foreach (FlagOverride::query()->get(['feature_flag_id', 'tenant_id', 'enabled']) as $o) {
                $this->overrides[$o->feature_flag_id][$o->tenant_id] = $o->enabled;
            }
        }

        return $this->overrides;
    }
}
