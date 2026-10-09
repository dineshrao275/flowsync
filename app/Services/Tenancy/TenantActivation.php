<?php

namespace App\Services\Tenancy;

use App\Jobs\ProvisionTenantJob;
use App\Models\Tenant;
use App\Services\TenantLifecycle;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The only door from a `draft` tenant to a real one. Refuses while any required
 * intake field is missing (no database is created), otherwise hands the tenant
 * to the provisioning job together with its plan, trial and default user.
 */
class TenantActivation
{
    public function __construct(
        private readonly TenantIntake $intake,
        private readonly TenantLifecycle $lifecycle,
    ) {}

    public function activate(Tenant $tenant, string $password, bool $synchronous = false): void
    {
        $this->activateWithHash($tenant, Hash::make($password), $synchronous);
    }

    public function activateWithHash(Tenant $tenant, string $passwordHash, bool $synchronous = false): void
    {
        $state = $this->intake->state($tenant);

        if (! $state['complete']) {
            throw ValidationException::withMessages(
                collect($state['missing'])->mapWithKeys(fn (string $f): array => [$f => 'This field is required.'])->all()
            );
        }

        $this->lifecycle->assertTransition($tenant, Tenant::STATUS_PENDING);

        // The plan catalog must exist before the provisioning job plans anything.
        (new SubscriptionPlanSeeder)->run();

        $values = $state['values'];
        $trialDays = $this->intake->trialDays($tenant);

        $tenant->update(['trial_ends_at' => $trialDays ? now()->addDays($trialDays) : null]);
        $this->lifecycle->transition($tenant, Tenant::STATUS_PENDING);

        $job = new ProvisionTenantJob(
            $tenant,
            (int) $values['plan_id'],
            $trialDays ?? 0, // 0 = no trial, even when the plan has a default one
            [
                'name' => $values['admin_name'],
                'email' => $values['admin_email'],
                'password_hash' => $passwordHash,
            ],
            ($values['payment_method'] ?? null) ?: null,
        );

        $synchronous ? Bus::dispatchSync($job) : Bus::dispatch($job);
    }
}
