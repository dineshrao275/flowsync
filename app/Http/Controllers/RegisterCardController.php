<?php

namespace App\Http\Controllers;

use App\Billing\PaymentService;
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
use App\Support\UniqueSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service sign-up with a card on file for the trial.
 *
 *  1. `card`     validates the whole sign-up, parks it as a draft (the password as a
 *                hash only) and returns the hosted page that saves a card — no database yet.
 *  2. `complete` runs when the customer comes back: the saved card is verified with the
 *                provider, and only then is the tenant provisioned and the user signed in.
 *
 * A draft that never comes back is removed by `tenants:prune-drafts`.
 */
class RegisterCardController extends Controller
{
    public function __construct(
        private readonly TenantIntake $intake,
        private readonly TenantActivation $activation,
        private readonly TenantOnboarding $onboarding,
        private readonly PaymentService $payments,
        private readonly TenantDatabaseManager $dbm,
    ) {}

    public function card(RegisterRequest $request): JsonResponse
    {
        $this->assertOpen();
        $data = $request->validated();
        $email = Str::lower($data['email']);

        if (TenantUserRouting::where('email', $email)->exists() || SystemUser::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }

        $productPlans = array_filter(['tms_plan_id' => $data['tms_plan_id'] ?? null, 'hrms_plan_id' => $data['hrms_plan_id'] ?? null]);
        $planId = $data['plan_id'] ?? null;
        if (! $planId && ! $productPlans) {
            $planId = SubscriptionPlan::where('is_default', true)->where('is_active', true)->value('id');
        }
        $token = Str::random(48);

        $tenant = Tenant::create([
            'name' => $data['business_name'],
            'slug' => $this->uniqueSlug($data['slug'] ?? Str::limit($data['business_name'], 40, '')),
            'status' => Tenant::STATUS_DRAFT,
            'provisioning_status' => Tenant::PROVISIONING_PENDING,
        ]);
        $this->intake->save($tenant, [
            'industry' => $data['industry'], 'company_size' => $data['company_size'], 'country' => $data['country'],
            'billing_email' => $email, 'contact_name' => $data['name'], 'contact_email' => $email,
            'admin_name' => $data['name'], 'admin_email' => $email, 'plan_id' => $planId, 'start_trial' => true,
        ] + $productPlans);
        $meta = $tenant->onboarding_meta;
        $meta['intake'] += ['source' => 'register', 'card_token' => $token, 'password_hash' => Hash::make($data['password'])];
        $tenant->update(['onboarding_meta' => $meta]);

        try {
            $session = $this->payments->createCardSession(
                $tenant,
                url("/app/register/complete?session_id={CHECKOUT_SESSION_ID}&t={$token}"),
                url('/app/register?card=canceled'),
            );
        } catch (\RuntimeException $e) {
            $tenant->forceDelete();
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }

        $meta = $tenant->refresh()->onboarding_meta;
        $meta['intake']['card_session'] = $session['id'];
        $tenant->update(['onboarding_meta' => $meta]);

        return response()->json(['url' => $session['url']]);
    }

    public function complete(Request $request, AuthController $auth): JsonResponse
    {
        $this->assertOpen();
        $data = $request->validate(['session_id' => ['required', 'string', 'max:255'], 't' => ['required', 'string', 'size:48']]);

        $tenant = Tenant::where('status', Tenant::STATUS_DRAFT)
            ->where('onboarding_meta->intake->card_token', $data['t'])->first();
        abort_unless($tenant, 404, 'This sign-up link is no longer valid.');

        $intake = $tenant->onboarding_meta['intake'];
        // The session must be the one created for this very draft.
        abort_unless(hash_equals((string) ($intake['card_session'] ?? ''), $data['session_id']), 422, 'This card session does not belong to the sign-up.');

        $card = $this->payments->retrieveCardSession($tenant, $data['session_id']);
        if (! $card['complete'] || ! $card['payment_method'] || (string) $card['reference'] !== (string) $tenant->id) {
            throw ValidationException::withMessages(['form' => 'We could not confirm your card. Please try again.']);
        }

        $this->intake->save($tenant, ['payment_method' => $card['payment_method']]);
        $tenant->refresh();
        if (! $this->intake->state($tenant)['complete']) {
            throw ValidationException::withMessages(['form' => 'Your sign-up is incomplete. Please start again.']);
        }

        $this->onboarding->start($tenant);
        foreach (['business', 'admin', 'subscription'] as $step) {
            $this->onboarding->markStep($tenant, $step);
        }

        $hash = $intake['password_hash'];
        $this->activation->activateWithHash($tenant, $hash, synchronous: true);

        $tenant->refresh();
        abort_unless($tenant->isProvisioned(), 422, 'We could not provision your workspace: '.($tenant->provisioning_error ?? 'unknown error'));

        // The password hash has done its job; do not keep it.
        $meta = $tenant->onboarding_meta;
        unset($meta['intake']['password_hash'], $meta['intake']['card_token']);
        $tenant->update(['onboarding_meta' => $meta]);

        $user = $this->dbm->using($tenant, fn () => User::where('email', $intake['admin_email'])->firstOrFail());

        return $auth->establishTenantSession($request, $user, $tenant, 'registration');
    }

    private function assertOpen(): void
    {
        abort_unless(PlatformSetting::bool('public_registration', config('onboarding.enabled') ? '1' : '0'), 403, 'Self-registration is currently disabled.');
    }

    private function uniqueSlug(string $slug): string
    {
        return UniqueSlug::make(
            Str::slug($slug ?: Str::random(6)),
            fn (string $candidate): bool => Tenant::withTrashed()->where('slug', $candidate)->exists(),
        );
    }
}
