<?php

namespace App\Services\Hrms\Leave;

use App\Models\Hrms\Leave\LeavePolicy;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — the type and policy catalogues.
 *
 * System rows may be renamed, recolored and reordered, but their structure
 * is locked: accrual semantics feed payroll and the balance engine, and a
 * "quick edit" to a seeded method would rewrite every balance it ever
 * computed. Deletion is refused while anything references the row — history
 * outlives the catalogue — and the default policy cannot be deleted while
 * the tenant has no other to fall back to.
 */
class LeaveCatalogService
{
    /**
     * Structural fields locked on system types. Everything else (name, slug,
     * code, color, position, active flag) is a presentation detail a tenant
     * owns.
     */
    private const SYSTEM_LOCKED = [
        'is_paid',
        'accrual_method',
        'accrual_rate',
        'max_balance',
        'carry_forward',
        'carry_forward_cap',
        'encashable',
        'requires_document_after_days',
        'min_days_per_request',
        'max_days_per_year',
        'allow_half_day',
        'allow_negative_balance',
    ];

    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /** @return Collection<int, LeaveType> */
    public function types(): Collection
    {
        return LeaveType::query()->withCount('policies')->orderBy('position')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createType(array $data, ?User $actor = null): LeaveType
    {
        $type = LeaveType::create([
            ...$data,
            'slug' => $this->uniqueTypeSlug((string) ($data['slug'] ?? ''), (string) $data['name']),
        ]);

        $this->audit->log($type, 'leave.type_created', null, ['slug' => $type->slug, 'name' => $type->name], $actor);

        return $type->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException on a structural edit to a system type
     */
    public function updateType(LeaveType $type, array $data, ?User $actor = null): LeaveType
    {
        if ($type->is_system) {
            $this->refuseStructuralEdit($type, $data);
        }

        $before = ['name' => $type->name, 'is_active' => $type->is_active];

        $type->update($data);

        $this->audit->log($type, 'leave.type_updated', $before, ['name' => $type->name, 'is_active' => $type->is_active], $actor);

        return $type->refresh();
    }

    /**
     * @throws ValidationException on a system type or a referenced one
     */
    public function deleteType(LeaveType $type, ?User $actor = null): void
    {
        if ($type->is_system) {
            throw ValidationException::withMessages(['form' => 'System leave types cannot be deleted. Deactivate one instead.']);
        }

        $references = [
            'balances' => $type->balances()->count(),
            'adjustments' => $type->adjustments()->count(),
            'requests' => $type->requests()->count(),
        ];

        if ($type->policies()->exists()) {
            throw ValidationException::withMessages(['form' => 'This type is linked to a policy. Unlink it first.']);
        }

        foreach ($references as $relation => $count) {
            if ($count > 0) {
                throw ValidationException::withMessages(['form' => "This type has {$count} {$relation} behind it and cannot be deleted."]);
            }
        }

        $snapshot = ['slug' => $type->slug, 'name' => $type->name];
        $type->delete();

        $this->audit->log($type, 'leave.type_deleted', $snapshot, null, $actor);
    }

    /** @return Collection<int, LeavePolicy> */
    public function policies(): Collection
    {
        return LeavePolicy::query()->withCount('types')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPolicy(array $data, ?User $actor = null): LeavePolicy
    {
        return DB::transaction(function () use ($data, $actor): LeavePolicy {
            if (($data['is_default'] ?? false) === true) {
                LeavePolicy::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $policy = LeavePolicy::create([
                ...$data,
                'slug' => $this->uniquePolicySlug((string) ($data['slug'] ?? ''), (string) $data['name']),
            ]);

            $this->audit->log($policy, 'leave.policy_created', null, ['slug' => $policy->slug], $actor);

            return $policy->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePolicy(LeavePolicy $policy, array $data, ?User $actor = null): LeavePolicy
    {
        return DB::transaction(function () use ($policy, $data, $actor): LeavePolicy {
            if (($data['is_default'] ?? false) === true) {
                LeavePolicy::query()->whereKeyNot($policy->id)->where('is_default', true)->update(['is_default' => false]);
            }

            $before = ['name' => $policy->name, 'is_default' => $policy->is_default, 'is_active' => $policy->is_active];

            $policy->update($data);

            $this->audit->log($policy, 'leave.policy_updated', $before, [
                'name' => $policy->name,
                'is_default' => $policy->is_default,
                'is_active' => $policy->is_active,
            ], $actor);

            return $policy->refresh();
        });
    }

    /**
     * @throws ValidationException on the default policy or a linked one
     */
    public function deletePolicy(LeavePolicy $policy, ?User $actor = null): void
    {
        if ($policy->is_default) {
            throw ValidationException::withMessages(['form' => 'The default policy cannot be deleted. Make another policy default first.']);
        }

        if ($policy->types()->exists()) {
            throw ValidationException::withMessages(['form' => 'This policy still covers leave types. Unlink them first.']);
        }

        $snapshot = ['slug' => $policy->slug, 'name' => $policy->name];
        $policy->delete();

        $this->audit->log($policy, 'leave.policy_deleted', $snapshot, null, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function refuseStructuralEdit(LeaveType $type, array $data): void
    {
        foreach (self::SYSTEM_LOCKED as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            // Loose on purpose: '30' and 30 are the same rate, and flagging
            // a resubmitted form for a type-coerced twin would cry wolf.
            if ($data[$field] != $type->getAttribute($field)) {
                throw ValidationException::withMessages([
                    'form' => "System leave types keep their {$field}; rename or deactivate instead.",
                ]);
            }
        }
    }

    /**
     * Server-allocated slugs with an auto-suffix (the departments precedent):
     * accepting a client's would let two types collide on the string payroll
     * joins against.
     */
    private function uniqueTypeSlug(string $slug, string $name): string
    {
        $base = $slug !== '' ? Str::slug($slug) : Str::slug($name);
        $candidate = $base;
        $suffix = 2;

        while (LeaveType::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function uniquePolicySlug(string $slug, string $name): string
    {
        $base = $slug !== '' ? Str::slug($slug) : Str::slug($name);
        $candidate = $base;
        $suffix = 2;

        while (LeavePolicy::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
