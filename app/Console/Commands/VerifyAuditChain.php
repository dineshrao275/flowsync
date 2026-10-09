<?php

namespace App\Console\Commands;

use App\Services\Security\AuditChain;
use Illuminate\Console\Command;

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

            return self::FAILURE;
        }

        $this->info('Chain intact.');

        return self::SUCCESS;
    }
}
