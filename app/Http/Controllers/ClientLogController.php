<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ClientLogStoreRequest;
use App\Services\Errors\ErrorRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Ingests frontend client-side logs (React ErrorBoundary crashes, unhandled
 * promise rejections, API network failures) into the daily date-wise Laravel
 * logs and the central Super Admin error store (`source=frontend`).
 */
class ClientLogController extends Controller
{
    public function __construct(private readonly ErrorRecorder $recorder) {}

    public function store(ClientLogStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $rawLevel = strtolower($validated['level'] ?? 'error');
        $level = match ($rawLevel) {
            'warn', 'warning' => 'warning',
            'debug' => 'debug',
            'info' => 'info',
            default => 'error',
        };

        $message = $validated['message'];
        $context = $validated['context'] ?? [];
        $context['client_url'] = $validated['url'] ?? $request->header('Referer');
        $context['user_agent'] = $request->userAgent();
        $context['ip'] = $request->ip();

        if ($user = $request->user()) {
            $context['user_id'] = $user->id;
            $context['user_email'] = $user->email;
        }

        // The store record is written explicitly (source=frontend); the file
        // line below is marked so the global listener does not record it again.
        $this->recorder->recordMessage($level, "[Frontend] {$message}", $context, $request, 'frontend');

        Log::log($level, "[Frontend] {$message}", $context + ['error_capture' => false]);

        return response()->json(['ok' => true]);
    }
}
