<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Integrations\Git\GitConfigAudit;
use Illuminate\Console\Command;

/** `php artisan git:check-config` - is the GitHub App configured safely? Prints findings, never secrets. */
class GitCheckConfig extends Command
{
    protected $signature = 'git:check-config';

    protected $description = 'Check the GitHub App configuration (no secrets are printed)';

    public function handle(GitConfigAudit $audit): int
    {
        $failed = false;
        foreach ($audit->run() as [$level, $message]) {
            $failed = $failed || $level === 'fail';
            $this->line(sprintf('[%s] %s', strtoupper($level), $message));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
