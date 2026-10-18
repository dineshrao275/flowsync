<?php

/*
|--------------------------------------------------------------------------
| Billing lifecycle defaults (Phase 6)
|--------------------------------------------------------------------------
| Every value here is an engineering default chosen to keep today's
| behaviour safe, NOT a pricing or commercial decision. The owner may change
| any of them via env without a code change; none is read from the plan
| catalog, so changing them never rewrites a plan.
*/

return [

    'invoice' => [
        // Invoice numbers read "FS-000042"; the digits are the invoice row id.
        'prefix' => env('BILLING_INVOICE_PREFIX', 'FS'),
        // Shown in the PDF header and footer.
        'issuer' => env('BILLING_ISSUER_NAME', env('APP_NAME', 'FlowSync')),
        'footer' => env('BILLING_INVOICE_FOOTER', 'Thank you for your business.'),
    ],

    /*
    | Dunning (P6.2): what happens after a renewal charge fails. Day 0 is the
    | day the subscription went past_due. `steps` is {day => stage}; the
    | tenant is suspended once `grace_days` have passed with no payment, and
    | comes back automatically the moment an invoice is paid.
    */
    'dunning' => [
        'grace_days' => (int) env('BILLING_DUNNING_GRACE_DAYS', 7),
        'steps' => [
            0 => 'reminder',
            3 => 'reminder',
            5 => 'final_notice',
        ],
    ],

    /*
    | Period end (P6.3): how a subscription whose paid period has lapsed is
    | settled. Provider-billed subscriptions get `renewal_grace_hours` for the
    | provider's renewal webhook to arrive before they enter dunning.
    | `unbilled_policy` is what happens to a subscription that no provider
    | bills (platform-assigned, demo, free): `renew` keeps service running and
    | rolls the period forward; `expire` ends it like a cancelled plan.
    */
    'period_end' => [
        'renewal_grace_hours' => (int) env('BILLING_RENEWAL_GRACE_HOURS', 72),
        'unbilled_policy' => env('BILLING_UNBILLED_POLICY', 'renew'),
    ],

    /*
    | Proration (P6.4): a mid-period plan or seat change charges the new price
    | for the days left and credits the old price for the days not used.
    | Stripe prorates by itself (`proration_behavior=create_prorations`); this
    | calculation is for subscriptions FlowSync bills itself.
    | `credit_policy`: what happens when the credit exceeds the charge (a
    | downgrade) — `carry` records the credit on the event for the next
    | invoice, `none` forgives it.
    */
    'proration' => [
        'enabled' => (bool) env('BILLING_PRORATION_ENABLED', true),
        'credit_policy' => env('BILLING_PRORATION_CREDIT', 'carry'),
    ],

    /*
    | Overage policy (P6.4): what a tenant over a plan limit gets. `block`
    | is the shipped behaviour (TenantLimits::assertQuota refuses with a 422);
    | `allow_grace` and `bill` are named for the owner decision and are NOT
    | implemented — setting them today still blocks. No metered billing exists.
    */
    'overage' => [
        'policy' => env('BILLING_OVERAGE_POLICY', 'block'),
    ],

    /*
    | Stripe transport: bounded retries on transient failures (connection, 429, 5xx); every write carries an
    | Idempotency-Key, so a retry can never create a second customer, price or subscription.
    */
    'stripe' => [
        'attempts' => (int) env('STRIPE_ATTEMPTS', 3),
        'timeout' => (int) env('STRIPE_TIMEOUT', 20),
        'retry_sleep_ms' => (int) env('STRIPE_RETRY_SLEEP_MS', 250),
    ],

    /*
    | Price changes: a raised price reaches an existing subscriber only on a renewal at least this many days after we
    | told them by email (a decrease applies at the next renewal, no minimum). Card expiry: warn the billing contacts
    | this many days before the saved card expires. `lock_seconds`: the per-tenant lock around billing mutations.
    */
    'price_change_notice_days' => (int) env('BILLING_PRICE_NOTICE_DAYS', 30),
    'card_expiry_warning_days' => [30, 7],
    'lock_seconds' => 15,

    /*
    | Endpoints an EXPIRED tenant may still call (paths below `api/`; a trailing `*` is a prefix). Everything else answers
    | 402 `subscription_required` while the data stays untouched.
    */
    'restricted_allow' => [
        'auth/*', 'language', 'notifications*', 'notification-preferences', 'tenant/profile', 'onboarding*',
        'my-subscription*', 'my-usage', 'plans', 'billing/*', 'my-export*', 'support/*', 'security/*', 'impersonate/stop',
    ],
];
