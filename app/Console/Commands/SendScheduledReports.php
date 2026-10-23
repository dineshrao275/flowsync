<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\ReportBuilderController;
use App\Mail\ScheduledReportMail;
use App\Models\SavedReport;
use App\Models\Tenant;
use App\Services\Reports\ReportRunner;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Emails each due scheduled report to its owner, built with the owner's own row scope. Daily >20 h since last send, weekly >6.5 days. */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled {--all : every serviceable tenant}';

    protected $description = 'Send scheduled saved reports as CSV attachments';

    public function handle(TenantDatabaseManager $dbm, ReportRunner $runner): int
    {
        $sent = 0;

        foreach (Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get() as $tenant) {
            $dbm->using($tenant, function () use ($runner, &$sent): void {
                if (! Schema::hasTable('saved_reports')) {
                    return;
                }

                SavedReport::query()->where('schedule', '!=', 'none')->with('user')->get()->each(function (SavedReport $r) use ($runner, &$sent): void {
                    $gap = $r->schedule === 'weekly' ? 6.5 * 24 : 20;
                    if ($r->user === null || ($r->last_sent_at !== null && $r->last_sent_at->gt(now()->subHours((int) $gap)))) {
                        return;
                    }

                    try {
                        $out = $runner->run($r->user, $r->dataset, $r->columns, $r->filters ?? []);
                        Mail::to($r->user->email)->queue(new ScheduledReportMail($r->name, ReportBuilderController::csv($out['columns'], $out['rows']), count($out['rows']), $out['truncated']));
                        $r->forceFill(['last_sent_at' => now()])->save();
                        $sent++;
                    } catch (Throwable $e) {
                        $this->warn("Report #{$r->id} skipped: {$e->getMessage()}");
                    }
                });
            });
        }

        $this->info("Sent {$sent} scheduled report(s).");

        return self::SUCCESS;
    }
}
