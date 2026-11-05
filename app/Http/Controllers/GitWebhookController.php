<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Integrations\Git\Webhook\GitWebhookIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public GitHub App webhook receiver. The HMAC signature is the only credential. */
class GitWebhookController extends Controller
{
    public function __construct(private readonly GitWebhookIntake $intake) {}

    public function receive(Request $request): JsonResponse
    {
        $result = $this->intake->accept($request);

        return response()->json((object) $result['body'], $result['status']);
    }
}
