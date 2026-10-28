<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Database\Seeders\Realistic\Verifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Checks the realistic demo dataset (tenant mix, entitlements, relations, dates, arithmetic) and writes a record-count report. */
class VerifyRealistic extends Command
{
    protected $signature = 'tenants:verify-realistic {--slug=* : only these tenants} {--no-central : skip the 150-tenant mix checks} {--report= : write a markdown count report to this path} {--fail-fast}';

    protected $description = 'Verify the realistic demo dataset and print a record-count report';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $verifier = new Verifier;
        $dbm->connectSystem();
        $tenants = Tenant::withTrashed()->orderBy('id')->get()->filter(fn (Tenant $t) => isset($t->settings['demo_seed']))
            ->when($this->option('slug'), fn ($c) => $c->whereIn('slug', $this->option('slug')));

        if (! $this->option('no-central') && ! $this->option('slug')) {
            $verifier->central();
        }

        $counts = [];
        foreach ($tenants as $tenant) {
            $before = count($verifier->failures());
            $verifier->tenant($tenant, $dbm);
            $dbm->connectSystem();
            $new = count($verifier->failures()) - $before;
            $this->line(sprintf('%-46s %s', $tenant->slug, $new === 0 ? 'ok' : "$new problem(s)"));
            if ($new && $this->option('fail-fast')) {
                break;
            }
            if ($this->option('report')) {
                $dbm->using($tenant, function () use (&$counts): void {
                    foreach (Schema::getTableListing() as $table) {
                        $table = preg_replace('/^public\./', '', $table);
                        $counts[$table] = ($counts[$table] ?? 0) + (int) DB::table($table)->count();
                    }
                });
                $dbm->connectSystem();
            }
        }

        foreach ($verifier->failures() as $failure) {
            $this->error($failure);
        }
        if ($this->option('report')) {
            ksort($counts);
            $md = '| table | rows across '.count($tenants)." tenants |\n|---|---:|\n";
            foreach ($counts as $table => $n) {
                $md .= "| $table | ".number_format($n)." |\n";
            }
            file_put_contents($this->option('report'), $md);
            $this->info('Report written to '.$this->option('report'));
        }

        $this->line(count($verifier->failures()) === 0 ? 'All checks passed.' : count($verifier->failures()).' problem(s) found.');

        return count($verifier->failures()) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
