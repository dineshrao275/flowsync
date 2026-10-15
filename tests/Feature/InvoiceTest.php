<?php

namespace Tests\Feature;

use App\Billing\Invoices\InvoiceService;
use App\Billing\PaymentResolver;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use App\Support\Pdf\SimplePdf;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P6.1 — invoices are generated on payment events, listed per tenant and per platform, and rendered to PDF. */
class InvoiceTest extends TestCase
{
    use IsolatesDatabase;

    private const WEBHOOK_SECRET = 'whsec_invoice_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.driver' => 'stripe',
            'payments.gateways.stripe.secret' => 'sk_test_unit',
            'payments.gateways.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        app()->forgetInstance(PaymentResolver::class);
    }

    private function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->firstOrFail();
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function renewalWebhook(): void
    {
        $tenant = $this->acme();
        app(SubscriptionService::class)->assign($tenant, SubscriptionPlan::where('slug', 'pro')->firstOrFail());
        $tenant->refresh()->subscription->update(['billing_provider' => 'stripe', 'provider_subscription_id' => 'sub_inv', 'status' => 'active']);

        $event = [
            'id' => 'evt_inv_a', 'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_a', 'subscription' => 'sub_inv', 'billing_reason' => 'subscription_cycle',
                'amount_paid' => 3400, 'currency' => 'usd', 'payment_intent' => 'pi_a', 'tax' => 0,
                'lines' => ['data' => [
                    ['description' => 'Pro plan', 'amount' => 2900, 'quantity' => 1, 'period' => ['start' => now()->timestamp, 'end' => now()->addMonth()->timestamp]],
                    ['description' => 'Unused time on Starter', 'amount' => 500, 'quantity' => 1, 'proration' => true],
                ]],
            ]],
        ];
        $body = json_encode($event);
        $t = time();
        $headers = ['Stripe-Signature' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", self::WEBHOOK_SECRET), 'Content-Type' => 'application/json'];

        $this->call('POST', '/api/webhooks/stripe', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertOk();
    }

    public function test_a_stripe_renewal_creates_one_itemised_invoice_and_a_replay_adds_none(): void
    {
        $this->renewalWebhook();
        $this->renewalWebhook();

        $this->assertSame(1, Invoice::count());
        $invoice = Invoice::with('lines')->first();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('in_a', $invoice->provider_invoice_id);
        $this->assertSame(3400, $invoice->total_cents);
        $this->assertSame(['subscription', 'proration'], $invoice->lines->pluck('kind')->all());
        $this->assertMatchesRegularExpression('/^FS-\d{6}$/', $invoice->number);
    }

    public function test_the_tenant_admin_lists_and_downloads_its_own_invoice_pdf(): void
    {
        $this->renewalWebhook();
        $invoice = Invoice::firstOrFail();
        $this->login('admin@flowsync.test');

        $this->getJson('/api/billing/invoices')->assertOk()->assertJsonPath('invoices.0.number', $invoice->number);

        $pdf = $this->get("/api/billing/invoices/{$invoice->id}/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdf->getContent());
        $this->assertStringContainsString($invoice->number, $pdf->getContent());
    }

    public function test_another_tenants_invoice_is_a_404(): void
    {
        $globex = Tenant::where('slug', 'globex')->firstOrFail();
        $foreign = app(InvoiceService::class)->issueOpen($globex, null, [['description' => 'Proration', 'amount_cents' => 100]]);
        $this->login('admin@flowsync.test');

        $this->get("/api/billing/invoices/{$foreign->id}/pdf")->assertNotFound();
        $this->getJson('/api/billing/invoices')->assertOk()->assertJsonCount(0, 'invoices');
    }

    public function test_a_user_without_a_billing_grant_is_refused(): void
    {
        $this->login('viewer@flowsync.test');

        $this->getJson('/api/billing/invoices')->assertForbidden();
    }

    public function test_the_super_admin_lists_every_tenants_invoices_and_filters_by_tenant(): void
    {
        $service = app(InvoiceService::class);
        $service->issueOpen($this->acme(), null, [['description' => 'A', 'amount_cents' => 100]]);
        $service->issueOpen(Tenant::where('slug', 'globex')->firstOrFail(), null, [['description' => 'B', 'amount_cents' => 200]]);
        $this->login('superadmin@flowsync.test');

        $this->getJson('/api/system/invoices')->assertOk()->assertJsonPath('pagination.total', 2);
        $this->getJson('/api/system/invoices?tenant_id='.$this->acme()->id)->assertOk()->assertJsonPath('pagination.total', 1);
        $this->get('/api/system/invoices/'.Invoice::first()->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_pdf_writer_emits_a_well_formed_multi_page_document(): void
    {
        $pdf = new SimplePdf;
        for ($i = 0; $i < 90; $i++) {
            $pdf->line("Line {$i} (with parens) \\ and “quotes” €");
        }
        $out = $pdf->output();

        $this->assertStringStartsWith('%PDF-1.4', $out);
        $this->assertStringContainsString('/Count 2', $out);
        $this->assertStringEndsWith("%%EOF\n", $out);
        $startxref = (int) trim(substr($out, strrpos($out, 'startxref') + 9, 12));
        $this->assertSame('xref', substr($out, $startxref, 4));
    }
}
