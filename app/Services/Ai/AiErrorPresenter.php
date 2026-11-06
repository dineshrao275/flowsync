<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiErrorKind;
use App\Services\Ai\Exceptions\AiProviderException;
use Throwable;

/**
 * Maps AI failures to a status + a user-safe body. Provider text and configuration details
 * (which key is missing, what the provider said) stay in the logs: a normal user only learns
 * "unavailable", "limit reached" or "try again".
 */
final class AiErrorPresenter
{
    /** @return array{status:int, body:array{message:string, code:string}} */
    public static function present(Throwable $e): array
    {
        return match (true) {
            $e instanceof AiLimitExceededException => ['status' => 429, 'body' => ['message' => $e->getMessage(), 'code' => 'ai_limit']],
            $e instanceof AiConfigurationException => ['status' => 503, 'body' => ['message' => __('The AI assistant is not available right now.'), 'code' => 'ai_unavailable']],
            $e instanceof AiProviderException && $e->kind === AiErrorKind::Unsupported => ['status' => 422, 'body' => ['message' => __('The AI assistant cannot do that with the current setup.'), 'code' => 'ai_unsupported']],
            $e instanceof AiProviderException && $e->kind === AiErrorKind::RateLimit => ['status' => 429, 'body' => ['message' => __('The AI service is busy. Please try again in a moment.'), 'code' => 'ai_busy']],
            $e instanceof AiProviderException => ['status' => $e->kind->httpStatus(), 'body' => ['message' => __('The AI assistant could not answer. Please try again.'), 'code' => 'ai_error']],
            default => ['status' => 500, 'body' => ['message' => __('Something went wrong.'), 'code' => 'ai_error']],
        };
    }
}
