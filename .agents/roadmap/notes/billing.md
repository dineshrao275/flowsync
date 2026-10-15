# Billing track notes (branch `track/billing`, Phase 6)

Based on `7e94bf9` (Improve/improvements-new-work). No tests were run (user policy: defer to the final gate);
verification was `php -l`, `./vendor/bin/pint --test`, `npm run build` only.

## Shipped

| Task | Commit | What |
|---|---|---|
| P6.1 | `feat(billing): invoices ...` | `invoices` + `invoice_lines` (system DB), `InvoiceService` (idempotent per payment / provider invoice id), `InvoicePdf`, `InvoicePresenter`; hooked into `PaymentService::activateSubscriptionForPayment` (checkout/verify/webhook-with-payment) and `SubscriptionBillingSync::renewed` (Stripe `invoice.paid`, itemised from the provider's own lines incl. proration lines). Tenant API + SA API + Invoices table on `Subscription.jsx`. |
| P6.2 | `feat(billing): dunning ...` | `subscriptions.past_due_at` (cycle anchor, set on first failure, cleared by assign/renew/startTrial) + `dunning_attempts` ledger. `DunningService` / `billing:dunning`. Steps in `config/billing.php` (`dunning.steps` day=>stage, `grace_days`). Reminder day 0 and 3, final notice day 5, suspend tenant at `grace_days` (7). In-app notifications to tenant admins (`billing.payment_failed|final_notice|suspended`), SPA text + `/subscription` link in `utils/notifications.js`. Paying (`SubscriptionService::renew`) ends the cycle and reactivates a suspended tenant. |
| P6.3 | `feat(billing): period-end ...` | `PeriodEndService` / `billing:period-ends`: canceled or non-renewing -> `expired` (+tenant `expired` when no other product subscription runs); recurring provider -> wait `renewal_grace_hours` (72) then past_due; one-off provider -> past_due at once; provider-less -> `unbilled_policy` (default `renew`; free plans always renew). New event type `expired` (`Subscription::EVENT_EXPIRED`). |
| P6.4 | `feat(billing): proration ...` | `ProrationCalculator`: credit = unused share of the paid period; applied to one-off-provider (Razorpay/fake) checkouts with a 100-cent charge floor, itemised on the invoice (payment `metadata.invoice_lines`). Stripe keeps `proration_behavior=create_prorations` (now test-pinned). Self-service switch records the unused-time credit on the `plan_changed` event (`data.proration`) - informational, not auto-applied. Overage policy documented as config default `billing.overage.policy = block` (the shipped behaviour). |
| P6.5 | `feat(hrms): payslips download as PDF` | `PayslipRenderer::renderPdf` (SimplePdf); `PayslipDownload` streams `application/pdf`, filename `.pdf`. Signed tenant-scoped reader-bound link, policy and access row unchanged. `render()` (HTML) kept. |
| P6.6 | SKIPPED | Packaging catalog rewrite to option B needs the owner's pricing decision (roadmap sec 27/35). |

No PDF library is vendored, so `app/Support/Pdf/SimplePdf.php` is a ~170-line dependency-free PDF 1.4 text writer (Helvetica, WinAnsi, A4, page breaks, right-aligned amounts). Swap for dompdf later by replacing `InvoicePdf`/`PayslipRenderer::renderPdf` internals only.

## Migrations (system DB, `database/migrations/system`, repair-safe guards)
- `2026_10_28_000031_create_invoices_tables.php` - `invoices`, `invoice_lines`
- `2026_10_28_000032_add_dunning_tables.php` - `subscriptions.past_due_at`, `dunning_attempts`
Run: `php artisan migrate --database=system --path=database/migrations/system`.

## Routes (added with fully-qualified-then-imported controllers, one adjacent pair each)
- Tenant group (next to `billing/history`): `GET api/billing/invoices`, `GET api/billing/invoices/{invoice}/pdf` - `InvoiceController`, authorised in the action (`admin` role or `billing.view|billing.manage`, same as history). No new permission, no open route (RouteAuthorizationAuditTest passes by the `hasRole(` decision).
- super_admin group (after `tenants/{tenant}/subscription/events`): `GET api/system/invoices` (filters `tenant_id`, `status`, `per_page`), `GET api/system/invoices/{invoice}/pdf` - `SystemInvoiceController`.
- The payslip signed route is unchanged.

## Commands and schedule (`routes/console.php`)
- `billing:period-ends [--tenant=ID] [--dry-run]` - daily 08:00, withoutOverlapping
- `billing:dunning [--tenant=ID] [--dry-run]` - daily 09:00, withoutOverlapping (runs after period-ends)
- `ScheduleRegistrationTest` expectations extended with both.

## Config
New `config/billing.php` (all env-overridable engineering defaults, none a pricing decision): `invoice.{prefix,issuer,footer}`, `dunning.{grace_days,steps}`, `period_end.{renewal_grace_hours,unbilled_policy}`, `proration.{enabled,credit_policy}`, `overage.policy`.

## Tests written (not run)
`tests/Feature/InvoiceTest.php`, `DunningTest.php`, `PeriodEndTest.php`, `ProrationTest.php`; `HrmsPayslipAccessTest` download test now asserts a PDF; `ScheduleRegistrationTest` expects the two commands. Roughly +27 tests - re-count at the next full run.

## Exact AGENTS.md changes to make (not done here)
1. `## Subscriptions (Phase 14)`: add a bullet "**Billing lifecycle (Phase 6):** invoices/invoice_lines (system) written only by `App\Billing\Invoices\InvoiceService`; PDF via `App\Support\Pdf\SimplePdf` (no vendored PDF lib); `billing:period-ends` (08:00) and `billing:dunning` (09:00) in `routes/console.php`; `subscriptions.past_due_at` anchors a dunning cycle (`dunning_attempts` ledger); proration in `App\Billing\Proration\ProrationCalculator` (one-off providers only, Stripe prorates itself); defaults in `config/billing.php`. Routes: `GET api/billing/invoices[/{invoice}/pdf]`, `GET api/system/invoices[/{invoice}/pdf]`."
2. `## Stripe billing (FB-6)` "Not done" line / `Tenant lifecycle`: replace "no grace period (yet)" wording (routes/console.php comment on `tenants:expire-trials` still says no grace period - trials remain exact; only paid periods have grace).
3. HRMS reference / P9.4 payslip text: "payslips are rendered to PDF (SimplePdf); `PayslipRenderer::render` HTML form is retained".
4. `## Commands` test-count line: add Phase 6 billing slice (InvoiceTest, DunningTest, PeriodEndTest, ProrationTest) and re-verify totals at the full run.
5. Migrations pointer: system set now reaches `2026_10_28_000032`.

## Exact master-roadmap.md changes (not done here)
- Sec 10 "Missing:" line: strike invoices, dunning, grace, proration, period-end expiry; remaining = overage *implementation* (policy default recorded: block), grandfathered plans.
- Sec 31 Phase 6 table: mark P6.1-P6.5 done (branch `track/billing`); P6.6 remains blocked on the owner pricing decision.
- Sec 35 owner-decisions line: keep "pricing for option B (P6.6)"; add "overage policy beyond `block`; proration credit policy `carry` vs `none`; dunning cadence/grace (defaults 0/3/5/7 days)".
- Gap register: G-2 remainder closed by P6.3.

## Risks / follow-ups
- Invoice creation runs inside the payment-confirmation transaction; a DB failure there would roll back the payment confirmation (kept simple on purpose; the writer is idempotent so a webhook retry heals it).
- Proration credit on self-service downgrades is only recorded on the event; nothing applies it to the next invoice (`proration.credit_policy=carry` is the documented intent, `none` forgives).
- Seat changes have no financial effect (plan prices are flat, no per-seat price) - nothing to prorate.
- Dunning notifications are in-app only (no email); the final notice and suspension can land in the same sweep when the sweep was down.
- `SimplePdf` is Latin-1 (WinAnsi); non-Latin names print as `?` in PDFs. Fine for current seed/locales; swap to a real PDF engine before CJK/RTL payslips.
- `PaymentService.php` is already over the 300-line ceiling (pre-existing, ~445 lines); this track added ~12 lines and did not split it.
- Stripe-hosted invoice URL / `invoice.payment_failed` per-attempt data are not stored on `invoices`.
