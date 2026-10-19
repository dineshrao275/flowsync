<?php

namespace App\Console\Commands;

use App\Support\SiteShowcase;
use Illuminate\Console\Command;

/** Import the product screenshots into the media library and add the product tour to the home/features pages. */
class SiteImportShowcase extends Command
{
    protected $signature = 'site:import-showcase';

    protected $description = 'Import product screenshots and add the "See it in action" tour to the public site';

    public function handle(): int
    {
        $changed = SiteShowcase::install();
        $this->info($changed ? "Updated {$changed} page(s)." : 'Nothing to change — the tour is already on the site.');

        return self::SUCCESS;
    }
}
