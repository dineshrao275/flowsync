<?php

namespace Database\Seeders\Realistic;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** System-database side of one tenant: the tenant row, its subscriptions, their events, invoices and payments. */
final class CentralSeeder
{
    private const COMPANY_SIZES = [[25, '11-50'], [50, '51-200'], [200, '201-500']];

    public function createTenant(array $bp, Timeline $tl): Tenant
    {
        [$cc, $country, $tz, $currency, $state, $city] = $bp['country'];
        Rng::seed($bp['index'], 'tenant');

        $size = collect(self::COMPANY_SIZES)->first(fn ($s) => $bp['users'] <= $s[0])[1] ?? '201-500';
        $contact = Rng::pick(NameBank::FIRST_NAMES).' '.Rng::pick(NameBank::LAST_NAMES);
        $domain = $bp['domain'];

        $tenant = Tenant::query()->create([
            'name' => $bp['name'],
            'slug' => $bp['slug'],
            'description' => NameBank::INDUSTRIES[$bp['industry']][0].' company based in '.$city.', '.$country.'.',
            'legal_name' => $bp['name'],
            'registration_number' => strtoupper($cc).mt_rand(10000000, 99999999),
            'tax_id' => strtoupper($cc).mt_rand(100000000, 999999999),
            'country' => $country,
            'street' => mt_rand(10, 480).' '.Rng::pick(['Market Street', 'Park Avenue', 'Station Road', 'Riverside Drive', 'Industrial Estate', 'High Street']),
            'city' => $city,
            'state' => $state,
            'postal_code' => (string) mt_rand(100000, 999999),
            'website' => "https://www.{$domain}",
            'industry' => ucfirst($bp['industry']),
            'company_size' => $size,
            'contact_name' => $contact,
            'contact_email' => 'admin@'.$domain,
            'contact_phone' => '+'.mt_rand(10, 99).' '.mt_rand(70000, 99999).' '.mt_rand(10000, 99999),
            'billing_email' => 'billing@'.$domain,
            'billing_currency' => $currency,
            'timezone' => $tz,
            'locale' => in_array($cc, ['ES'], true) ? 'es' : 'en',
            'brand_domain' => $domain,
            'brand_primary_color' => Rng::pick(['#2563eb', '#0d9488', '#7c3aed', '#dc2626', '#ea580c', '#0891b2']),
            'trial_ends_at' => $tl->trialEndsAt,
            'status' => Tenant::STATUS_PENDING,
            'onboarding_meta' => ['started_at' => $tl->created->toIso8601String(), 'completed_at' => $tl->created->addMinutes(20)->toIso8601String()],
            'settings' => ['demo_seed' => ['version' => (int) config('demo_seed.version'), 'category' => $bp['category'], 'state' => $bp['state'], 'complete' => false]],
        ]);

        // created_at is not mass-assignable on every model; pin it so tenant age matches the timeline.
        DB::connection(config('tenancy.system.connection'))->table('tenants')->where('id', $tenant->id)->update([
            'created_at' => $tl->created, 'updated_at' => $tl->created,
        ]);

        return $tenant->refresh();
    }

    /**
     * @param  list<string>  $planSlugs
     * @return array<string, Subscription> product => subscription
     */
    public function subscribe(Tenant $tenant, array $bp, Timeline $tl, int $users): array
    {
        $db = DB::connection(config('tenancy.system.connection'));
        $plans = SubscriptionPlan::whereIn('slug', $bp['plans'])->get()->keyBy('slug');
        $out = [];
        $events = [];
        $now = $tl->now;
        Rng::seed($bp['index'], 'billing');

        foreach ($bp['plans'] as $slug) {
            /** @var SubscriptionPlan $plan */
            $plan = $plans[$slug];
            $months = $plan->billing_cycle === 'annual' ? 12 : 1;
            $trial = $bp['state'] === 'trialing';

            // Period containing "end" (the last moment this subscription was in force).
            $start = $trial ? $tl->created : $this->periodStart($tl, $months);
            $periodEnd = $trial ? $tl->trialEndsAt : $start->addMonths($months);
            $status = match ($bp['state']) {
                'trialing' => Subscription::STATUS_TRIALING,
                'active' => Subscription::STATUS_ACTIVE,
                'past_due' => Subscription::STATUS_PAST_DUE,
                'expired' => Subscription::STATUS_EXPIRED,
                default => Subscription::STATUS_CANCELED,
            };
            if ($bp['state'] === 'expired') {
                $periodEnd = $tl->expiredAt;
            }
            if ($bp['state'] === 'canceled') {
                $periodEnd = $tl->canceledAt;
            }

            $planForSub = $plan;
            $changeFrom = null;
            if ($bp['state'] === 'active' && $bp['index'] % 3 === 0) {
                $changeFrom = $this->neighbourPlan($plan, $bp['index'] % 2 === 0 ? -1 : 1);
            }

            $subId = $db->table('subscriptions')->insertGetId([
                'tenant_id' => $tenant->id,
                'product' => $plan->product ?? 'suite',
                'plan_id' => $planForSub->id,
                'status' => $status,
                'current_period_start' => $start,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => $trial ? $tl->trialEndsAt : null,
                'canceled_at' => $tl->canceledAt,
                'past_due_at' => $tl->pastDueAt,
                'auto_renew' => in_array($bp['state'], ['trialing', 'active', 'past_due'], true),
                'seats' => (int) max($users, (int) $plan->limit('users')),
                'billing_provider' => $plan->price_cents > 0 ? 'mock' : null,
                'billing_reference' => $plan->price_cents > 0 ? 'demo_'.Str::lower(Str::random(10)) : null,
                'created_at' => $tl->created, 'updated_at' => $now,
            ]);
            $sub = Subscription::query()->find($subId);
            $out[$sub->product] = $sub;

            $ev = function (string $type, $at, array $extra = []) use (&$events, $tenant, $subId, $planForSub): void {
                $events[] = array_merge([
                    'tenant_id' => $tenant->id, 'subscription_id' => $subId, 'type' => $type, 'from_plan_id' => null,
                    'to_plan_id' => $planForSub->id, 'data' => null, 'actor_id' => null, 'created_at' => $at, 'updated_at' => $at,
                ], $extra);
            };

            $ev('trial_started', $tl->created, ['data' => json_encode(['days' => $tl->trialDays])]);
            if (! $trial) {
                $ev('trial_expired', $tl->created->addDays($tl->trialDays));
                $ev('subscribed', $tl->created->addDays($tl->trialDays + 1));
            }
            if ($changeFrom && ! $trial) {
                $ev('plan_changed', $tl->created->addDays(max($tl->trialDays + 5, (int) ($tl->days() * 0.5))), ['from_plan_id' => $changeFrom->id, 'data' => json_encode(['direction' => $changeFrom->price_cents < $plan->price_cents ? 'upgrade' : 'downgrade'])]);
            }
            if ($bp['state'] === 'past_due') {
                $ev('payment_failed', $tl->pastDueAt->subDay(), ['data' => json_encode(['reason' => 'card_declined'])]);
                $ev('paused', $tl->pastDueAt);
            }
            if ($bp['state'] === 'canceled') {
                $ev('canceled', $tl->canceledAt, ['data' => json_encode(['reason' => Rng::pick(['Budget cuts', 'Switched provider', 'Company wound down', 'Not using it enough'])])]);
            }
            if ($bp['state'] === 'expired') {
                $ev('expired', $tl->expiredAt);
            }

            // Renewals, newest first, capped: one invoice + completed payment per paid period.
            if (! $trial && $plan->price_cents > 0) {
                $this->bill($tenant, $sub, $plan, $tl, $months, $events, $bp, $tl->created->addDays($tl->trialDays + 1));
            }
        }

        $db->table('subscription_events')->insert($events);
        $primary = collect(['suite', 'tms', 'hrms'])->map(fn ($p) => $out[$p] ?? null)->filter()->first();
        $db->table('tenants')->where('id', $tenant->id)->update(['subscription_id' => $primary?->id]);

        return $out;
    }

    /** @param list<array<string, mixed>> $events */
    private function bill(Tenant $tenant, Subscription $sub, SubscriptionPlan $plan, Timeline $tl, int $months, array &$events, array $bp, $firstPaidAt): void
    {
        $db = DB::connection(config('tenancy.system.connection'));
        $periods = [];
        $cursor = $firstPaidAt->startOfDay();
        while ($cursor->lte($tl->end) && count($periods) < 12) {
            $periods[] = $cursor;
            $cursor = $cursor->addMonths($months);
        }
        $periods = array_slice($periods, -6); // keep the six most recent

        $tax = in_array($bp['country'][0], ['IN', 'GB', 'DE', 'ES', 'AU', 'SG'], true) ? 0.18 : 0.0;
        foreach ($periods as $n => $at) {
            $subtotal = (int) $plan->price_cents * max(1, (int) ($sub->seats > 0 ? 1 : 1));
            $taxCents = (int) round($subtotal * $tax);
            $failed = $bp['state'] === 'past_due' && $n === array_key_last($periods);
            $paymentId = $db->table('payments')->insertGetId([
                'tenant_id' => $tenant->id, 'subscription_id' => $sub->id, 'provider' => 'mock',
                'provider_payment_id' => 'pay_'.Str::lower(Str::random(14)), 'amount_cents' => $subtotal + $taxCents, 'currency' => strtolower($plan->currency ?? 'usd'),
                'status' => $failed ? 'failed' : 'completed', 'idempotency_key' => "demo-{$tenant->id}-{$sub->id}-{$n}",
                'failure_reason' => $failed ? 'card_declined' : null, 'created_at' => $at, 'updated_at' => $at,
            ]);
            $invoiceId = $db->table('invoices')->insertGetId([
                'tenant_id' => $tenant->id, 'subscription_id' => $sub->id, 'payment_id' => $paymentId,
                'number' => sprintf('INV-%04d-%s-%02d', $tenant->id, strtoupper(substr($plan->product ?? 'suite', 0, 1)), $n + 1),
                'status' => $failed ? 'open' : 'paid', 'currency' => strtolower($plan->currency ?? 'usd'),
                'subtotal_cents' => $subtotal, 'tax_cents' => $taxCents, 'total_cents' => $subtotal + $taxCents,
                'amount_paid_cents' => $failed ? 0 : $subtotal + $taxCents, 'provider' => 'mock', 'provider_invoice_id' => 'in_'.Str::lower(Str::random(14)),
                'billing_name' => $tenant->legal_name, 'billing_email' => $tenant->billing_email,
                'issued_at' => $at, 'paid_at' => $failed ? null : $at->addMinutes(3),
                'period_start' => $at, 'period_end' => $at->addMonths($months), 'created_at' => $at, 'updated_at' => $at,
            ]);
            $db->table('invoice_lines')->insert([[
                'invoice_id' => $invoiceId, 'kind' => 'subscription', 'description' => $plan->name.' — '.($months === 12 ? 'annual' : 'monthly'),
                'quantity' => 1, 'unit_amount_cents' => $subtotal, 'amount_cents' => $subtotal, 'created_at' => $at, 'updated_at' => $at,
            ]]);

            if ($n > 0) {
                $events[] = ['tenant_id' => $tenant->id, 'subscription_id' => $sub->id, 'type' => $failed ? 'payment_failed' : 'renewed', 'from_plan_id' => null, 'to_plan_id' => $plan->id, 'data' => null, 'actor_id' => null, 'created_at' => $at, 'updated_at' => $at];
            }
        }
    }

    private function periodStart(Timeline $tl, int $months)
    {
        $start = $tl->created->addDays($tl->trialDays + 1)->startOfDay();
        while ($start->addMonths($months)->lte($tl->end)) {
            $start = $start->addMonths($months);
        }

        return $start;
    }

    /** The plan one tier below (-1) or above (+1) in the same product family; null when there is none. */
    private function neighbourPlan(SubscriptionPlan $plan, int $step): ?SubscriptionPlan
    {
        $family = SubscriptionPlan::where('product', $plan->product)->orderBy('price_cents')->orderBy('id')->get()->values();
        $i = $family->search(fn ($p) => $p->id === $plan->id);

        return $family[$i + $step] ?? null;
    }

    /** Mirror every tenant login into the central routing index in one insert. */
    public function route(Tenant $tenant, array $users): void
    {
        $rows = [];
        foreach ($users as $u) {
            $rows[] = ['tenant_id' => $tenant->id, 'email' => $u['email'], 'user_id' => $u['id'], 'name' => $u['name'], 'created_at' => $u['created_at'], 'updated_at' => $u['created_at']];
        }
        TenantUserRouting::where('tenant_id', $tenant->id)->whereNotIn('email', array_column($users, 'email'))->delete();
        $existing = TenantUserRouting::where('tenant_id', $tenant->id)->pluck('email')->all();
        $fresh = array_values(array_filter($rows, fn ($r) => ! in_array($r['email'], $existing, true)));
        foreach (array_chunk($fresh, 1000) as $chunk) {
            TenantUserRouting::insert($chunk);
        }
    }
}
