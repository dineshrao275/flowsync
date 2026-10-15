<?php

namespace App\Http\Resources\Hrms\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared/HRMS — the per-tenant entitlement payload.
 *
 * A single `toArray()` so the shape is defined once (D2.16.4): the admin
 * checklist, the tenant detail page and the tenants-table pill all read the
 * same keys. `groups` is passed in pre-built, so the presenter does no
 * querying and no grouping of its own.
 */
class TenantHrmsResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $status  from TenantHrmsService::status()
     * @param  list<array<string, mixed>>  $groups  from ModuleTree::groups()
     */
    public function __construct(
        private readonly array $status,
        private readonly array $groups,
    ) {
        parent::__construct($status);
    }

    /**
     * @return array{enabled: bool, source: string, modules: array<string,bool>, effective_modules: list<string>, effective_hrms: list<string>, groups: list<array<string,mixed>>}
     */
    public function toArray(Request $request): array
    {
        return [
            'enabled' => $this->status['enabled'],
            'source' => $this->status['source'],
            'modules' => $this->status['modules'],
            'effective_modules' => $this->status['effective_modules'],
            'effective_hrms' => $this->status['effective_hrms'],
            'groups' => $this->groups,
        ];
    }
}
