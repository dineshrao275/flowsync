<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiChatRequest;
use App\Models\Tenant;
use App\Services\Ai\AiConversationService;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiOrchestrator;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiUsageService;
use App\Services\Ai\SseEmitter;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AiChatController extends Controller
{
    public function __construct(
        private readonly AiProviderResolver $resolver,
        private readonly AiUsageService $usage,
        private readonly AiConversationService $conversations,
        private readonly AiOrchestrator $orchestrator,
        private readonly TenantContext $context,
        private readonly TenantDatabaseManager $databases,
    ) {}

    public function store(AiChatRequest $request): Response
    {
        $user = $request->user();
        $input = $request->validated();

        // Fail before anything is created: a disabled/misconfigured assistant or an exhausted allowance.
        try {
            $provider = $this->resolver->provider();
            $this->usage->assertWithinLimits();
        } catch (Throwable $e) {
            return $this->failure($e);
        }

        $conversation = isset($input['conversation_id'])
            ? $this->conversations->findFor($user, (int) $input['conversation_id'])
            : $this->conversations->start($user, $input['context']['product'] ?? null, $input['message']);
        abort_if($conversation === null, 404);

        $context = $input['context'] ?? [];
        $stream = (bool) ($input['stream'] ?? false) && $provider->capabilities()->streaming;

        if (! $stream) {
            try {
                return response()->json($this->orchestrator->run($user, $conversation, $input['message'], $context)->toArray());
            } catch (Throwable $e) {
                return $this->failure($e);
            }
        }

        $tenant = Tenant::findOrFail($this->context->currentId());

        return response()->stream(function () use ($user, $conversation, $input, $context, $tenant): void {
            $sse = new SseEmitter;

            // The response body is produced after the middleware stack has unwound, so re-enter the tenant database explicitly.
            $this->databases->using($tenant, function () use ($user, $conversation, $input, $context, $sse): void {
                try {
                    $sse->send('start', ['conversation_id' => $conversation->id]);
                    $result = $this->orchestrator->run($user, $conversation, $input['message'], $context, true, $sse->send(...));
                    $sse->send('done', $result->toArray());
                } catch (Throwable $e) {
                    $sse->send('error', AiErrorPresenter::present($e)['body']);
                }
            });
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function failure(Throwable $e): JsonResponse
    {
        $presented = AiErrorPresenter::present($e);

        return response()->json($presented['body'], $presented['status']);
    }
}
