<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionTenantJob;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\TenantOnboarding;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public self-service registration for new tenants (behind the
 * onboarding.enabled toggle, default off). Creates the central tenant row, starts
 * the onboarding wizard, synchronously provisions the tenant DB, claims the
 * seeded owner account for the registrant, and auto-logs them in.
 */
class RegisterController extends Controller
{
    public function __construct(
        private readonly TenantOnboarding $onboarding,
        private readonly TenantDatabaseManager $dbm,
        private readonly TenantProvisioner $provisioner,
    ) {}

    public function store(Request $request, AuthController $auth): JsonResponse
    {
        if (! PlatformSetting::bool('public_registration', config('onboarding.enabled') ? '1' : '0')) {
            abort(403, 'Self-registration is currently disabled.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'business_name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
        ]);

        $email = Str::lower($data['email']);

        $this->assertEmailAvailable($email);

        $slug = $this->uniqueSlug(Str::slug($data['slug'] ?? Str::limit($data['business_name'], 40, '')));

        $planId = $data['plan_id']
            ?? SubscriptionPlan::query()->where('is_default', true)->where('is_active', true)->value('id');

        $plan = $planId ? SubscriptionPlan::find($planId) : null;
        $trialDays = $plan?->trial_duration_days ?: config('onboarding.trial_days');
        $trialDays = $trialDays ? max(1, (int) $trialDays) : null;

        $tenant = Tenant::create([
            'name' => $data['business_name'],
            'slug' => $slug,
            'status' => Tenant::STATUS_PENDING,
            'provisioning_status' => Tenant::PROVISIONING_PENDING,
            'billing_email' => $email,
            'contact_name' => $data['name'],
            'contact_email' => $email,
            'trial_ends_at' => $trialDays ? now()->addDays($trialDays) : null,
        ]);

        $this->onboarding->start($tenant);

        // Sync provision so the registrant can log in immediately (idempotent;
        // the queued path is equivalent when the queue runs synchronously).
        Bus::dispatchSync(new ProvisionTenantJob($tenant, $planId, $trialDays));

        $tenant->refresh();

        if (! $tenant->isProvisioned()) {
            abort(422, 'We could not provision your workspace: '.($tenant->provisioning_error ?? 'unknown error'));
        }

        $user = $this->claimOwnerAccount($tenant, $data);

        return $auth->establishTenantSession($request, $user, $tenant);
    }

    /**
     * Swap the provisioned `owner@{slug}.test` account for the registrant's
     * credentials, then re-mirror the tenant_users routing index.
     */
    private function claimOwnerAccount(Tenant $tenant, array $data): User
    {
        $email = Str::lower($data['email']);

        $user = $this->dbm->using($tenant, function () use ($tenant, $data, $email): User {
            $user = User::where('email', "owner@{$tenant->slug}.test")->first()
                ?? (new User)->forceFill(['email' => "owner@{$tenant->slug}.test"]);

            $user->name = $data['name'];
            $user->email = $email;
            $user->password = $data['password']; // 'hashed' cast mutator
            $user->save();

            return $user;
        });

        // Drop the provisioned owner routing row (email swapped to the
        // registrant's) so the routing index never points at a ghost login.
        TenantUserRouting::where('tenant_id', $tenant->id)
            ->where('email', "owner@{$tenant->slug}.test")
            ->delete();

        $this->provisioner->syncRouting($this->dbm, $tenant);

        return $user;
    }

    private function assertEmailAvailable(string $email): void
    {
        if (TenantUserRouting::where('email', $email)->exists()
            || SystemUser::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email already exists.',
            ]);
        }
    }

    private function uniqueSlug(string $slug): string
    {
        $base = Str::slug($slug ?: Str::random(6));
        $candidate = $base;
        $i = 2;

        while (Tenant::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$i++;
        }

        return $candidate;
    }
}
