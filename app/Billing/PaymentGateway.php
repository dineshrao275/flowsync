<?php

namespace App\Billing;

use App\Billing\DTOs\CheckoutSession;
use App\Billing\DTOs\PaymentResult;
use App\Billing\DTOs\RefundResult;
use App\Billing\DTOs\WebhookResult;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Http\Request;

interface PaymentGateway
{
    /** Gateway identifier slug ('stripe', 'razorpay', 'mock'). */
    public function name(): string;

    /** Create a hosted checkout session or order. */
    public function createCheckoutSession(
        Tenant $tenant,
        SubscriptionPlan $plan,
        Payment $payment,
        array $options = []
    ): CheckoutSession;

    /** Verify payment state directly with the provider. */
    public function verifyPayment(string $providerPaymentId, array $payload = []): PaymentResult;

    /** Issue a partial or full refund against a completed payment. */
    public function refund(Payment $payment, ?int $amountCents = null, ?string $reason = null): RefundResult;

    /** Verify the authenticity of an incoming webhook HTTP signature. */
    public function verifyWebhookSignature(Request $request): bool;

    /** Parse an incoming webhook request and extract normalized event result. */
    public function handleWebhook(Request $request): WebhookResult;
}
