<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiUsage as UsageRow;
use App\Models\Tenant;
use App\Services\Ai\Dto\AiUsage;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Per-tenant daily AI allowance. Limits come from the plan (`ai_requests_per_day`,
 * `ai_tokens_per_day`, via TenantLimits so overrides apply) with config/ai.php as the platform
 * default; null means unlimited. Rows live in the central DB and hold counts only, never text.
 */
final class AiUsageService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantLimits $limits,
    ) {}

    /** @throws AiLimitExceededException */
    public function assertWithinLimits(): void
    {
        $tenant = $this->tenant();
        if ($tenant === null) {
            return;
        }

        $today = $this->today($tenant->id);
        $requests = $this->limit($tenant, 'ai_requests_per_day', config('ai.daily_request_limit'));
        $tokens = $this->limit($tenant, 'ai_tokens_per_day', config('ai.daily_token_limit'));

        if ($requests !== null && $today->requests >= $requests) {
            throw new AiLimitExceededException('request', $requests);
        }
        if ($tokens !== null && ($today->input_tokens + $today->output_tokens) >= $tokens) {
            throw new AiLimitExceededException('token', $tokens);
        }
    }

    public function record(AiUsage $usage, string $model, bool $failed = false, int $documentPages = 0): void
    {
        $tenant = $this->tenant();
        if ($tenant === null) {
            return;
        }

        $row = $this->today($tenant->id);
        $row->newQuery()->whereKey($row->getKey())->incrementEach(array_filter([
            'requests' => 1,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'document_pages' => $documentPages,
            'failures' => $failed ? 1 : 0,
            'cost_estimate' => $this->cost($model, $usage),
        ]));
    }

    /** @return array{requests:int,tokens:int,requests_limit:?int,tokens_limit:?int} */
    public function snapshot(): array
    {
        $tenant = $this->tenant();
        $today = $tenant ? $this->today($tenant->id) : new UsageRow;

        return [
            'requests' => (int) $today->requests,
            'tokens' => (int) $today->input_tokens + (int) $today->output_tokens,
            'requests_limit' => $tenant ? $this->limit($tenant, 'ai_requests_per_day', config('ai.daily_request_limit')) : null,
            'tokens_limit' => $tenant ? $this->limit($tenant, 'ai_tokens_per_day', config('ai.daily_token_limit')) : null,
        ];
    }

    /** Estimated USD for one call, from config/ai.php pricing; 0 for an unpriced model. */
    public function cost(string $model, AiUsage $usage): float
    {
        // Index the array: model ids contain dots, which config() would read as nesting.
        [$in, $out] = (array) (config('ai.pricing', [])[$model] ?? [0.0, 0.0]);

        return ($usage->inputTokens * $in + $usage->outputTokens * $out) / 1_000_000;
    }

    private function limit(Tenant $tenant, string $key, mixed $default): ?int
    {
        $value = $this->limits->limit($tenant, $key) ?? $default;

        return $value === null ? null : (int) $value;
    }

    private function tenant(): ?Tenant
    {
        $id = $this->context->currentId();

        return $id === null ? null : Tenant::find($id);
    }

    private function today(int $tenantId): UsageRow
    {
        $key = ['tenant_id' => $tenantId, 'day' => now()->toDateString()];

        try {
            return UsageRow::firstOrCreate($key);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created today's row between our read and write.
            return UsageRow::where($key)->firstOrFail();
        }
    }
}
