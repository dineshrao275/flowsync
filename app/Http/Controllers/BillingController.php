<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\BillingLock;
use App\Billing\PaymentMethods;
use App\Billing\PaymentService;
use App\Billing\Stripe\BillingProviderException;
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
        private readonly PaymentMethods $methods,
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
        abort_unless($plan->is_active, 422, __('This plan is not available.'));

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
            $scheduled = ($result['mode'] ?? null) === 'scheduled';

            return response()->json([
                'message' => $scheduled
                    ? __('Plan change scheduled for the end of your current billing period. You will not be charged twice.')
                    : __('Plan updated.'),
                'changed' => true,
                'mode' => $result['mode'] ?? 'immediate',
                'effective_at' => $result['effective_at'] ?? null,
            ]);
        }

        return response()->json([
            'message' => __('Checkout session created.'),
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
        } catch (BillingProviderException $e) {
            throw $e; // answered as 503 by the exception handler; Stripe's wording never reaches the tenant
        } catch (\RuntimeException $e) {
            // A refused or unfinished payment is the caller's answer, not a server fault.
            abort(422, $e->getMessage());
        }

        return response()->json([
            'message' => __('Payment verified and plan activated.'),
            'payment' => $this->presentPayment($updated),
        ]);
    }

    /** Hosted billing portal: update the card, see invoices (recurring gateways only). */
    public function portal(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $url = $this->paymentService->portalUrl($this->currentTenant(), url('/app/subscription'));
        } catch (BillingProviderException $e) {
            throw $e; // answered as 503 by the exception handler; Stripe's wording never reaches the tenant
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['url' => $url]);
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        $this->authorizeBillingView($request);

        return response()->json($this->methods->list($this->currentTenant()));
    }

    /** Hosted page to add a card; the browser comes back to /subscription?card_session={CHECKOUT_SESSION_ID}. */
    public function setupPaymentMethod(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $url = $this->methods->startSetup(
            $this->currentTenant(),
            url('/app/subscription?card_session={CHECKOUT_SESSION_ID}'),
            url('/app/subscription?card_setup=canceled'),
        );

        return response()->json(['url' => $url]);
    }

    public function completePaymentMethod(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['session_id' => ['required', 'string', 'max:255']]);

        $tenant = $this->currentTenant();
        BillingLock::for($tenant, fn () => $this->methods->completeSetup($tenant, $data['session_id']));

        return response()->json(['message' => __('Payment method saved.'), ...$this->methods->list($tenant)]);
    }

    public function makeDefaultPaymentMethod(Request $request, string $paymentMethod): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenant = $this->currentTenant();
        BillingLock::for($tenant, fn () => $this->methods->makeDefault($tenant, $paymentMethod));

        return response()->json(['message' => __('Default payment method updated.'), ...$this->methods->list($tenant)]);
    }

    public function removePaymentMethod(Request $request, string $paymentMethod): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenant = $this->currentTenant();
        BillingLock::for($tenant, fn () => $this->methods->remove($tenant, $paymentMethod));

        return response()->json(['message' => __('Payment method removed.'), ...$this->methods->list($tenant)]);
    }

    private function authorizeBillingView(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user?->hasRole('admin') || $user?->hasPermission('billing.view') || $user?->hasPermission('billing.manage'),
            403,
            __('You do not have permission to view billing information.')
        );
    }

    public function history(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();

        $user = $request->user();
        abort_unless(
            $user?->hasRole('admin') || $user?->hasPermission('billing.view') || $user?->hasPermission('billing.manage'),
            403,
            __('You do not have permission to view billing information.')
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
            __('Unauthorized to issue refund.')
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
            'message' => __('Refund processed.'),
            'payment' => $this->presentPayment($refunded),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->is_super_admin || $request->user()?->hasRole('admin') || $request->user()?->hasPermission('billing.manage'),
            403,
            __('Only tenant admins can manage billing.')
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
