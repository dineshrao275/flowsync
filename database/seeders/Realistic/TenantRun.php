<?php

namespace Database\Seeders\Realistic;

use App\Models\Tenant;
use App\Services\TenantLimits;
use Carbon\CarbonImmutable;

/** Everything one tenant's seeders share: blueprint, timeline, entitlements, the people and the id allocator. */
final class TenantRun
{
    /** @var list<array<string, mixed>> */
    public array $people = [];

    /** @var array<string, bool> */
    private array $modules = [];

    public function __construct(
        public readonly array $bp,
        public readonly Timeline $tl,
        public readonly Tenant $tenant,
        public readonly Bulk $bulk,
        public readonly string $passwordHash,
    ) {}

    public function now(): CarbonImmutable
    {
        return $this->tl->now;
    }

    public function hasProduct(string $product): bool
    {
        return in_array($product, $this->bp['products'], true);
    }

    /** Plan-entitled module? Cached; reads the effective limits (plans ⊕ override) like the application does. */
    public function module(string $key): bool
    {
        return $this->modules[$key] ??= app(TenantLimits::class)->hasModule($this->tenant, $key);
    }

    public function refreshEntitlements(): void
    {
        $this->modules = [];
        TenantLimits::resetMemo();
        $this->tenant->unsetRelation('subscriptions');
        $this->tenant->refresh();
    }

    /** @return list<array<string, mixed>> people with a given role slug */
    public function withRole(string $slug): array
    {
        return array_values(array_filter($this->people, fn ($p) => in_array($slug, $p['roles'], true)));
    }
}
