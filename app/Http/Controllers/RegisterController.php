<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\Tenancy\TenantActivation;
use App\Services\Tenancy\TenantIntake;
use App\Services\TenantOnboarding;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
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
        private readonly TenantIntake $intake,
        private readonly TenantActivation $activation,
    ) {}

    /** Public: what the registration wizard needs to render its plan step. */
    public function options(): JsonResponse
    {
        abort_unless(PlatformSetting::bool('public_registration', config('onboarding.enabled') ? '1' : '0'), 403, 'Self-registration is currently disabled.');

        return response()->json([
            'plans' => SubscriptionPlan::where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name', 'slug', 'description', 'price_cents', 'currency', 'billing_cycle', 'trial_duration_days', 'is_default']),
            'trial_days' => (int) config('onboarding.trial_days', 14),
            'require_card_for_trial' => (bool) config('onboarding.require_card_for_trial'),
        ]);
    }

    public function store(RegisterRequest $request, AuthController $auth): JsonResponse
    {
        if (! PlatformSetting::bool('public_registration', config('onboarding.enabled') ? '1' : '0')) {
            abort(403, 'Self-registration is currently disabled.');
        }

        $data = $request->validated();

        $email = Str::lower($data['email']);

        $this->assertEmailAvailable($email);

        $slug = $this->uniqueSlug(Str::slug($data['slug'] ?? Str::limit($data['business_name'], 40, '')));

        $planId = $data['plan_id']
            ?? SubscriptionPlan::query()->where('is_default', true)->where('is_active', true)->value('id');

        // Same intake as the Super Admin wizard: the registrant is the contact,
        // the billing contact and the default user, and the database is only
        // created once every required field is in.
        $tenant = Tenant::create([
            'name' => $data['business_name'],
            'slug' => $slug,
            'status' => Tenant::STATUS_DRAFT,
            'provisioning_status' => Tenant::PROVISIONING_PENDING,
        ]);
        $this->intake->save($tenant, [
            'industry' => $data['industry'],
            'company_size' => $data['company_size'],
            'country' => $data['country'],
            'billing_email' => $email,
            'contact_name' => $data['name'],
            'contact_email' => $email,
            'admin_name' => $data['name'],
            'admin_email' => $email,
            'plan_id' => $planId,
            'start_trial' => $data['start_trial'] ?? true,
            'payment_method' => $data['payment_method'] ?? null,
        ]);

        // The wizard's first three steps are what intake just collected.
        $this->onboarding->start($tenant);
        foreach (['business', 'admin', 'subscription'] as $step) {
            $this->onboarding->markStep($tenant, $step);
        }

        // Sync so the registrant can log in immediately.
        $this->activation->activate($tenant, $data['password'], synchronous: true);

        $tenant->refresh();

        if (! $tenant->isProvisioned()) {
            abort(422, 'We could not provision your workspace: '.($tenant->provisioning_error ?? 'unknown error'));
        }

        $user = $this->dbm->using($tenant, fn () => User::where('email', $email)->firstOrFail());

        return $auth->establishTenantSession($request, $user, $tenant, 'registration');
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
