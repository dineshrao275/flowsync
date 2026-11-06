<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\AiConfigurationService;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiUsageService;
use App\Services\Ai\Tools\AiToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the SPA needs to decide how to draw the assistant. Never carries a key, a model name or a provider error. */
class AiStatusController extends Controller
{
    public function __invoke(
        Request $request,
        AiProviderResolver $resolver,
        AiConfigurationService $config,
        AiUsageService $usage,
        AiToolRegistry $tools,
    ): JsonResponse {
        $user = $request->user();
        $available = $resolver->isAvailable();

        return response()->json([
            'available' => $available,
            'streaming' => $available && $resolver->provider()->capabilities()->streaming,
            'tools' => $available ? array_keys($tools->availableFor($user)) : [],
            'usage' => $usage->snapshot(),
            // Setup problems are for people who can fix them.
            'problems' => $user->hasPermission('ai.manage') && $config->enabled() ? $config->problems() : [],
        ]);
    }
}
