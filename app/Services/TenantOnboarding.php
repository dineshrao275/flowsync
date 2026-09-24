<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Tenant onboarding tracker (central `tenants.onboarding_meta`).
 *
 * Lifecycle:
 * - `start()`         marks a tenant as mid-wizard (self-registration).
 * - `markStep()`      completes one catalog step (business, admin, subscription,
 *                     configuration, verification).
 * - `complete()`      stamps every step + the terminal `completed_at`.
 * - `isComplete()`    a tenant is complete when completed_at is set, OR the
 *                     wizard was never started (admin/seed-provisioned gate
 *                     automatically), OR every required step is done.
 */
class TenantOnboarding
{
    /**
     * Steps a user can complete through the wizard (excludes the terminal step).
     */
    private const COMPLETABLE_STEPS = [
        'business',
        'admin',
        'subscription',
        'configuration',
        'verification',
    ];

    public function catalog(): array
    {
        return config('onboarding.steps', []);
    }

    /**
     * Wizard-completable steps (everything except the terminal step, which is
     * only reachable through complete()).
     *
     * @return list<string>
     */
    public function completableSteps(): array
    {
        return array_values(array_intersect(self::COMPLETABLE_STEPS, array_keys($this->catalog())));
    }

    /**
     * Full per-step + overall status for the wizard/UI.
     */
    public function status(Tenant $tenant): array
    {
        $meta = $tenant->onboarding_meta ?? [];

        $steps = collect($this->catalog())->map(function (array $step, string $key) use ($meta): array {
            $entry = $meta['steps'][$key] ?? [];

            return [
                'key' => $key,
                'title' => $step['title'],
                'description' => $step['description'] ?? null,
                'required' => (bool) ($step['required'] ?? true),
                'complete' => ! empty($entry['completed_at']),
                'completed_at' => $entry['completed_at'] ?? null,
            ];
        })->values()->all();

        $startedAt = $meta['started_at'] ?? null;
        $completedAt = $meta['completed_at'] ?? null;

        return [
            'steps' => $steps,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'overall' => $completedAt ? 'complete' : ($startedAt ? 'in_progress' : 'pending'),
            'complete' => $this->isComplete($tenant),
        ];
    }

    /**
     * Kick off the wizard (self-registration entry point).
     */
    public function start(Tenant $tenant): void
    {
        $meta = $tenant->onboarding_meta ?? [];

        if (empty($meta['started_at'])) {
            $meta['started_at'] = Carbon::now()->toISOString();
            $tenant->update(['onboarding_meta' => $meta]);
        }
    }

    /**
     * Mark a single wizard step complete (stamps started_at on first use).
     */
    public function markStep(Tenant $tenant, string $step, ?array $data = null): void
    {
        $this->assertCompletableStep($step);

        $meta = $tenant->onboarding_meta ?? [];
        $meta['started_at'] ??= Carbon::now()->toISOString();
        $meta['steps'][$step] ??= [];
        $meta['steps'][$step]['completed_at'] = Carbon::now()->toISOString();

        if ($data !== null) {
            $meta['steps'][$step]['data'] = $data;
        }

        $tenant->update(['onboarding_meta' => $meta]);
    }

    /**
     * Complete every step (including the terminal one) and stamp completed_at.
     */
    public function complete(Tenant $tenant): void
    {
        $meta = $tenant->onboarding_meta ?? [];
        $meta['started_at'] ??= Carbon::now()->toISOString();

        foreach (array_keys($this->catalog()) as $step) {
            $meta['steps'][$step] ??= [];
            $meta['steps'][$step]['completed_at'] = Carbon::now()->toISOString();
        }

        $meta['completed_at'] = Carbon::now()->toISOString();

        $tenant->update(['onboarding_meta' => $meta]);
    }

    /**
     * Clear the wizard state (super-admin repair).
     */
    public function reset(Tenant $tenant): void
    {
        $tenant->update(['onboarding_meta' => null]);
    }

    /**
     * Whether the tenant's domain routes are un-gated.
     */
    public function isComplete(Tenant $tenant): bool
    {
        $meta = $tenant->onboarding_meta ?? [];

        if (! empty($meta['completed_at'])) {
            return true;
        }

        // Tenants provisioned by admins/seeders never start the wizard and are
        // treated as complete — self-registration is the only entry point.
        if (empty($meta['started_at'])) {
            return true;
        }

        foreach ($this->catalog() as $key => $step) {
            if (($step['required'] ?? true) && empty($meta['steps'][$key]['completed_at'])) {
                return false;
            }
        }

        return true;
    }

    private function assertCompletableStep(string $step): void
    {
        if (! in_array($step, self::COMPLETABLE_STEPS, true) || ! array_key_exists($step, $this->catalog())) {
            throw new InvalidArgumentException("Unrecognized onboarding step [{$step}].");
        }
    }
}
