<?php

namespace App\Http\Controllers;

use App\Billing\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function handleStripe(Request $request): JsonResponse
    {
        $result = $this->paymentService->handleWebhook('stripe', $request);

        return response()->json($result);
    }

    public function handleRazorpay(Request $request): JsonResponse
    {
        $result = $this->paymentService->handleWebhook('razorpay', $request);

        return response()->json($result);
    }
}
