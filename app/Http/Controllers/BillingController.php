<?php

namespace App\Http\Controllers;

use App\Billing\PaymentService;
use App\Http\Controllers\Concerns\ResolvesCurrentTenant;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    use ResolvesCurrentTenant;

    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function checkout(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $tenant = $this->currentTenant();

        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'idempotency_key' => ['sometimes', 'string', 'max:255'],
        ]);

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);
        abort_unless($plan->is_active, 422, 'This plan is not available.');

        $result = $this->paymentService->initiateCheckout(
            tenant: $tenant,
            plan: $plan,
            user: $request->user(),
            options: [
                'currency' => $data['currency'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'customer_email' => $request->user()?->email,
            ]
        );

        if (! empty($result['changed'])) {
            return response()->json([
                'message' => 'Plan changed. The difference is prorated on your next invoice.',
                'changed' => true,
            ]);
        }

        return response()->json([
            'message' => 'Checkout session created.',
            'payment' => $this->presentPayment($result['payment']),
            'session' => $result['session'],
        ], 201);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $tenant = $this->currentTenant();

        $data = $request->validate([
            'payment_id' => ['required', 'integer'],
            'provider_payment_id' => ['required', 'string'],
            'razorpay_order_id' => ['sometimes', 'string'],
            'razorpay_signature' => ['sometimes', 'string'],
        ]);

        $payment = Payment::where('tenant_id', $tenant->id)->findOrFail($data['payment_id']);

        try {
            $updated = $this->paymentService->verifyPayment(
                tenant: $tenant,
                payment: $payment,
                providerPaymentId: $data['provider_payment_id'],
                payload: $data,
            );
        } catch (\RuntimeException $e) {
            // A refused or unfinished payment is the caller's answer, not a server fault.
            abort(422, $e->getMessage());
        }

        return response()->json([
            'message' => 'Payment verified and plan activated.',
            'payment' => $this->presentPayment($updated),
        ]);
    }

    /** Hosted billing portal: update the card, see invoices (recurring gateways only). */
    public function portal(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $url = $this->paymentService->portalUrl($this->currentTenant(), url('/app/subscription'));
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['url' => $url]);
    }

    public function history(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();

        $user = $request->user();
        abort_unless(
            $user?->hasRole('admin') || $user?->hasPermission('billing.view') || $user?->hasPermission('billing.manage'),
            403,
            'You do not have permission to view billing information.'
        );

        $payments = Payment::where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'payments' => $payments->map(fn ($p) => $this->presentPayment($p)),
        ]);
    }

    public function refund(Request $request, int $paymentId): JsonResponse
    {
        // Only super admin or tenant admin can trigger a refund
        abort_unless(
            $request->user()?->is_super_admin || $request->user()?->hasRole('admin') || $request->user()?->hasPermission('billing.manage'),
            403,
            'Unauthorized to issue refund.'
        );

        $tenant = $this->currentTenant();
        $payment = Payment::where('tenant_id', $tenant->id)->findOrFail($paymentId);

        $data = $request->validate([
            'amount_cents' => ['sometimes', 'integer', 'min:1'],
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        $refunded = $this->paymentService->refund(
            payment: $payment,
            amountCents: $data['amount_cents'] ?? null,
            reason: $data['reason'] ?? 'Tenant requested refund'
        );

        return response()->json([
            'message' => 'Refund processed.',
            'payment' => $this->presentPayment($refunded),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->is_super_admin || $request->user()?->hasRole('admin') || $request->user()?->hasPermission('billing.manage'),
            403,
            'Only tenant admins can manage billing.'
        );
    }

    private function presentPayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'provider' => $payment->provider,
            'amount_cents' => $payment->amount_cents,
            'formatted_amount' => $payment->formattedAmount(),
            'currency' => strtoupper($payment->currency),
            'status' => $payment->status,
            'idempotency_key' => $payment->idempotency_key,
            'provider_payment_id' => $payment->provider_payment_id,
            'provider_order_id' => $payment->provider_order_id,
            'failure_reason' => $payment->failure_reason,
            'plan_name' => $payment->metadata['plan_name'] ?? null,
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    protected function missingTenantMessage(): string
    {
        return 'No active tenant context.';
    }
}
