<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\AuditAlertMail;
use App\Services\Security\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Walks the platform audit hash chain (P8.6); exit code 1 on the first break. */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the tamper-evident hash chain of the platform audit trail';

    public function handle(AuditChain $chain): int
    {
        $report = $chain->verify();

        $this->line("Checked {$report['checked']} sealed rows, {$report['legacy']} legacy (unsealed), {$report['tombstones']} tombstones.");
        $this->line('Head: '.($report['head'] ?? '(none)'));

        if (! $report['ok']) {
            $this->error("Chain broken at audit row {$report['break']['id']}: {$report['break']['reason']}");
            $this->raiseAlert($report);

            return self::FAILURE;
        }

        $this->info('Chain intact.');

        return self::SUCCESS;
    }

    /**
     * A broken chain means a sealed audit row was edited or removed. Scheduled
     * runs have no operator watching, so say so loudly: a critical log line
     * (picked up by the error tracker) and, when configured, an email.
     *
     * @param  array<string, mixed>  $report
     */
    private function raiseAlert(array $report): void
    {
        $message = "Platform audit chain broken at row {$report['break']['id']}: {$report['break']['reason']}";

        Log::critical('audit.chain_broken', [
            'row_id' => $report['break']['id'],
            'reason' => $report['break']['reason'],
            'checked' => $report['checked'],
        ]);

        $breakId = (string) $report['break']['id'];
        $breakReason = (string) $report['break']['reason'];
        $checked = (int) $report['checked'];

        foreach ((array) config('security.audit_alert_emails', []) as $to) {
            Mail::to($to)->queue(new AuditAlertMail($breakId, $breakReason, $checked));
        }
    }
}
