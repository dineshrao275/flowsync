<?php

namespace App\Services\Tenancy;

use App\Models\SubscriptionPlan;
use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The one definition of "what a tenant must tell us before it exists".
 *
 * Both entry points — the Super Admin create/edit wizard and public
 * self-registration — validate against these rules and ask the same three
 * steps, so they cannot drift. Until every required field is present the
 * tenant stays a `draft` row and NO database is created; activation is the
 * only thing that provisions one.
 *
 * Business fields live on the tenant row; the default user and plan choice
 * live in `onboarding_meta.intake`. The default user's password is never
 * stored — it is supplied at activation and only its hash is queued.
 */
class TenantIntake
{
    public const STEPS = ['business', 'admin', 'plan'];

    public const BUSINESS_FIELDS = [
        'name', 'slug', 'industry', 'company_size', 'country',
        'billing_email', 'contact_name', 'contact_email',
    ];

    /** Optional business fields saved alongside the required ones. */
    public const BUSINESS_OPTIONAL = [
        'description', 'legal_name', 'website', 'contact_phone', 'timezone', 'locale',
    ];

    public const ADMIN_FIELDS = ['admin_name', 'admin_email'];

    public const PLAN_FIELDS = ['plan_id', 'tms_plan_id', 'hrms_plan_id'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $step, ?Tenant $tenant = null): array
    {
        return match ($step) {
            'business' => [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('tenants', 'slug')->ignore($tenant?->id)],
                'industry' => ['required', 'string', 'max:255'],
                'company_size' => ['required', 'string', 'max:255'],
                'country' => ['required', 'string', 'size:2'],
                'billing_email' => ['required', 'email', 'max:255'],
                'contact_name' => ['required', 'string', 'max:255'],
                'contact_email' => ['required', 'email', 'max:255'],
                'description' => ['nullable', 'string', 'max:255'],
                'legal_name' => ['nullable', 'string', 'max:255'],
                'website' => ['nullable', 'url', 'max:255'],
                'contact_phone' => ['nullable', 'string', 'max:64'],
                'timezone' => ['nullable', 'string', 'max:128'],
                'locale' => ['nullable', 'string', 'max:16'],
            ],
            'admin' => [
                'admin_name' => ['required', 'string', 'max:255'],
                'admin_email' => ['required', 'string', 'email', 'max:255', $this->emailIsFree()],
            ],
            'plan' => [
                // A legacy bundle, or one plan per product — at least one is required (checked in state()).
                'plan_id' => ['nullable', 'integer', Rule::exists('subscription_plans', 'id')->where('is_active', true)->where('product', 'suite')],
                'tms_plan_id' => ['nullable', 'integer', Rule::exists('subscription_plans', 'id')->where('is_active', true)->where('product', 'tms')],
                'hrms_plan_id' => ['nullable', 'integer', Rule::exists('subscription_plans', 'id')->where('is_active', true)->where('product', 'hrms')],
                'start_trial' => ['nullable', 'boolean'],
                // Set by the card-capture flow, never trusted from a client.
                'payment_method' => ['nullable', 'string', 'max:255'],
            ],
        };
    }

    /**
     * Merge partial step data into the draft. Only keys belonging to a step are
     * kept, so a client cannot write arbitrary tenant columns through intake.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Tenant $tenant, array $data): Tenant
    {
        $business = array_intersect_key($data, array_flip([...self::BUSINESS_FIELDS, ...self::BUSINESS_OPTIONAL]));
        if (isset($business['slug'])) {
            $business['slug'] = Str::slug($business['slug']);
        }
        if (isset($business['country'])) {
            $business['country'] = Str::upper($business['country']);
        }

        $meta = $tenant->onboarding_meta ?? [];
        $intake = $meta['intake'] ?? [];
        foreach ([...self::ADMIN_FIELDS, ...self::PLAN_FIELDS, 'start_trial', 'payment_method'] as $key) {
            if (array_key_exists($key, $data)) {
                $intake[$key] = $key === 'admin_email' ? Str::lower((string) $data[$key]) : $data[$key];
            }
        }
        $meta['intake'] = $intake;

        $tenant->fill($business);
        $tenant->onboarding_meta = $meta;
        $tenant->save();

        return $tenant;
    }

    /**
     * @return array{complete: bool, steps: array<string, array{complete: bool, missing: array<int, string>}>, missing: array<int, string>, values: array<string, mixed>}
     */
    public function state(Tenant $tenant): array
    {
        $intake = ($tenant->onboarding_meta ?? [])['intake'] ?? [];
        $get = fn (string $key): mixed => in_array($key, [...self::BUSINESS_FIELDS, ...self::BUSINESS_OPTIONAL], true)
            ? $tenant->getAttribute($key)
            : ($intake[$key] ?? null);

        $required = [
            'business' => self::BUSINESS_FIELDS,
            'admin' => self::ADMIN_FIELDS,
            'plan' => [],
        ];
        if ($this->cardRequired($intake)) {
            $required['plan'][] = 'payment_method';
        }

        $steps = [];
        $missing = [];
        foreach ($required as $step => $fields) {
            $gaps = array_values(array_filter($fields, fn (string $f): bool => blank($get($f))));
            // The plan step needs a bundle plan OR at least one per-product plan.
            if ($step === 'plan' && collect(self::PLAN_FIELDS)->every(fn (string $f): bool => blank($get($f)))) {
                $gaps[] = 'plan_id';
            }
            $steps[$step] = ['complete' => $gaps === [], 'missing' => $gaps];
            $missing = [...$missing, ...$gaps];
        }

        $values = [];
        foreach ([...self::BUSINESS_FIELDS, ...self::BUSINESS_OPTIONAL, ...self::ADMIN_FIELDS, ...self::PLAN_FIELDS, 'start_trial', 'payment_method'] as $key) {
            $values[$key] = $get($key);
        }

        return ['complete' => $missing === [], 'steps' => $steps, 'missing' => $missing, 'values' => $values];
    }

    public function trialDays(Tenant $tenant): ?int
    {
        $intake = ($tenant->onboarding_meta ?? [])['intake'] ?? [];

        return ! empty($intake['start_trial']) ? max(1, (int) config('onboarding.trial_days', 14)) : null;
    }

    /**
     * A trial needs a card on file — for self-service sign-ups, where the customer can
     * add one. A Super Admin creating a tenant by hand cannot enter someone else's card,
     * so that path is exempt and the tenant adds one from its billing page.
     *
     * @param  array<string, mixed>  $intake
     */
    public function cardRequired(array $intake): bool
    {
        return ! empty($intake['start_trial'])
            && ($intake['source'] ?? null) === 'register'
            && (bool) config('onboarding.require_card_for_trial');
    }

    public function planIsActive(?int $planId): bool
    {
        return $planId !== null && SubscriptionPlan::where('id', $planId)->where('is_active', true)->exists();
    }

    /** A login email must be unused platform-wide (routing index + super admins). */
    public function emailIsFree(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $email = Str::lower(trim((string) $value));
            if (TenantUserRouting::where('email', $email)->exists() || SystemUser::where('email', $email)->exists()) {
                $fail('An account with this email already exists.');
            }
        };
    }
}
