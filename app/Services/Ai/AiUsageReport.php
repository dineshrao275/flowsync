<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiUsage;

/** Platform-wide AI totals for today (central DB only, counts only), for the metrics endpoint and admin views. */
final class AiUsageReport
{
    /** @return array{tenants:int,requests:int,input_tokens:int,output_tokens:int,failures:int,cost:float} */
    public function today(): array
    {
        $rows = AiUsage::query()->where('day', now()->toDateString());

        return [
            'tenants' => (clone $rows)->count(),
            'requests' => (int) (clone $rows)->sum('requests'),
            'input_tokens' => (int) (clone $rows)->sum('input_tokens'),
            'output_tokens' => (int) (clone $rows)->sum('output_tokens'),
            'failures' => (int) (clone $rows)->sum('failures'),
            'cost' => (float) (clone $rows)->sum('cost_estimate'),
        ];
    }
}
