<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiConfigurationService;
use Illuminate\Console\Command;

/** Validates the AI configuration without calling any provider or printing a secret. */
class AiCheck extends Command
{
    protected $signature = 'ai:check';

    protected $description = 'Validate the AI assistant configuration (provider, model, key, capabilities)';

    public function handle(AiConfigurationService $config): int
    {
        $provider = $config->activeProvider();
        $settings = $config->settings();
        $caps = $config->capabilities();

        $this->table(['Setting', 'Value'], [
            ['enabled', $config->enabled() ? 'yes' : 'no'],
            ['provider', $provider],
            ['model', $settings['model'] ?: '(none)'],
            ['api key', $settings['key'] !== '' ? 'set' : 'MISSING'],
            ['base url', $settings['base_url'] ?: '(none)'],
            ['fallback', $config->fallbackProvider() ?? 'none'],
            ['capabilities', implode(', ', array_keys(array_filter($caps->toArray(), fn ($v) => $v === true))) ?: 'none'],
        ]);

        $problems = $config->problems();
        if ($problems === []) {
            $this->info($config->enabled() ? 'Configuration is valid.' : 'Configuration is valid (AI is currently disabled).');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return $config->enabled() ? self::FAILURE : self::SUCCESS;
    }
}
