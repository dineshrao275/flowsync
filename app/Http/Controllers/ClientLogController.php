<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ingests frontend client-side logs (React ErrorBoundary crashes, unhandled
 * promise rejections, API network failures) into the daily date-wise Laravel logs.
 */
class ClientLogController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level' => 'sometimes|string|in:debug,info,warn,warning,error',
            'message' => 'required|string|max:2000',
            'context' => 'sometimes|array',
            'url' => 'sometimes|nullable|string|max:1000',
        ]);

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

        Log::log($level, "[Frontend] {$message}", $context);

        return response()->json(['ok' => true]);
    }
}
