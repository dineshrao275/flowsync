<?php

namespace Database\Seeders\Realistic;

use App\Services\TenantLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Final step of a tenant: usage metrics for the last three months and `limits_override` where the demo exceeds a plan. */
final class UsageSeeder
{
    public function seed(TenantRun $run, array $subs): void
    {
        $counts = [
            'users' => (int) DB::table('users')->count(),
        ];
        if (Schema::hasTable('employees')) {
            $counts['employees'] = (int) DB::table('employees')->count();
        }
        if (Schema::hasTable('workspaces')) {
            $counts['workspaces'] = (int) DB::table('workspaces')->count();
            $counts['projects'] = (int) DB::table('projects')->count();
            $counts['tasks'] = (int) DB::table('tasks')->count();
        }
        if (Schema::hasTable('assets')) {
            $counts['assets'] = (int) DB::table('assets')->count();
        }

        // The demo deliberately exceeds small plans (25-200 users, thousands of tasks everywhere). Record that as an
        // explicit tenant override — the supported mechanism — instead of editing plan definitions.
        $limits = app(TenantLimits::class);
        $override = [];
        foreach ($counts as $key => $value) {
            $plan = $limits->limit($run->tenant, $key);
            if ($plan !== null && $value > (int) $plan) {
                $override[$key] = (int) ceil($value * 1.2);
            }
        }
        if ($override !== []) {
            $run->tenant->forceFill(['limits_override' => array_merge((array) $run->tenant->limits_override, $override)])->saveQuietly();
        }

        $rows = [];
        foreach ($counts as $key => $value) {
            for ($m = 0; $m < 3; $m++) {
                $period = $run->tl->end->subMonthsNoOverflow($m)->format('Y-m');
                if ($run->tl->end->subMonthsNoOverflow($m)->endOfMonth()->lt($run->tl->created)) {
                    continue;
                }
                // earlier months were smaller: a plausible growth curve ending at the real count
                $factor = max(0.3, 1 - 0.07 * $m);
                $rows[] = ['tenant_id' => $run->tenant->id, 'metric_key' => $key, 'period' => $period, 'value' => (int) round($value * $factor), 'created_at' => $run->now(), 'updated_at' => $run->now()];
            }
        }
        DB::connection(config('tenancy.system.connection'))->table('usage_metrics')->insert($rows);
    }
}
