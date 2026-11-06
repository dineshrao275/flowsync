<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Dto\AiCapabilities;
use App\Services\Ai\Exceptions\AiConfigurationException;

/**
 * Reads config/ai.php live (so a test or an env change is picked up without rebuilding anything),
 * resolves the effective settings and capabilities of a provider, and validates them.
 * Disabled AI is never validated: turning it off cannot break the rest of the app.
 */
final class AiConfigurationService
{
    public function enabled(): bool
    {
        return (bool) config('ai.enabled');
    }

    public function activeProvider(): string
    {
        return (string) config('ai.provider');
    }

    public function fallbackProvider(): ?string
    {
        $name = config('ai.fallback_provider');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** @return list<string> */
    public function knownProviders(): array
    {
        return array_keys((array) config('ai.providers', []));
    }

    public function driverOf(string $provider): string
    {
        return (string) config("ai.providers.{$provider}.driver", 'openai');
    }

    /**
     * Effective settings of one provider. The generic AI_API_KEY / AI_BASE_URL / AI_MODEL only
     * apply to the ACTIVE provider; the fallback keeps its own credentials and defaults.
     *
     * @return array{key:string,base_url:string,model:string,timeout:int,max_retries:int,retry_base_ms:int,max_output_tokens:int}
     */
    public function settings(?string $provider = null): array
    {
        $provider ??= $this->activeProvider();
        $active = $provider === $this->activeProvider();
        $entry = (array) config("ai.providers.{$provider}", []);

        return [
            'key' => (string) ($entry['key'] ?: ($active ? config('ai.api_key') : '')),
            'base_url' => (string) (($active ? config('ai.base_url') : null) ?: ($entry['base_url'] ?? '')),
            'model' => (string) (($active ? config('ai.model') : null) ?: ($entry['default_model'] ?? '')),
            'timeout' => (int) config('ai.timeout_seconds'),
            'max_retries' => (int) config('ai.max_retries'),
            'retry_base_ms' => (int) config('ai.retry_base_ms'),
            'max_output_tokens' => (int) config('ai.max_output_tokens'),
        ];
    }

    /** Provider defaults, corrected per model, then narrowed by the feature switches. */
    public function capabilities(?string $provider = null): AiCapabilities
    {
        $provider ??= $this->activeProvider();
        $entry = (array) config("ai.providers.{$provider}", []);
        $model = $this->settings($provider)['model'];

        $caps = array_merge((array) ($entry['capabilities'] ?? []), (array) ($entry['models'][$model] ?? []));
        $caps['streaming'] = ($caps['streaming'] ?? false) && config('ai.streaming_enabled');
        $caps['tools'] = ($caps['tools'] ?? false) && config('ai.tool_calling_enabled');

        return AiCapabilities::fromArray($caps);
    }

    /** @throws AiConfigurationException */
    public function assertUsable(): void
    {
        if (! $this->enabled()) {
            throw new AiConfigurationException(['AI is disabled (AI_ENABLED=false).']);
        }

        $this->validate();
    }

    /** @throws AiConfigurationException listing every problem found */
    public function validate(): void
    {
        $problems = $this->problems();

        if ($problems !== []) {
            throw new AiConfigurationException($problems);
        }
    }

    /** @return list<string> */
    public function problems(): array
    {
        $problems = [];
        $provider = $this->activeProvider();

        if (! in_array($provider, $this->knownProviders(), true)) {
            return ["AI_PROVIDER '{$provider}' is not supported (known: ".implode(', ', $this->knownProviders()).').'];
        }

        $problems = [...$problems, ...$this->providerProblems($provider)];

        if (config('ai.timeout_seconds') < 1) {
            $problems[] = 'AI_TIMEOUT_SECONDS must be at least 1.';
        }
        if (config('ai.max_retries') < 0 || config('ai.max_retries') > 5) {
            $problems[] = 'AI_MAX_RETRIES must be between 0 and 5.';
        }
        if (config('ai.max_output_tokens') < 1) {
            $problems[] = 'AI_MAX_OUTPUT_TOKENS must be at least 1.';
        }

        $fallback = $this->fallbackProvider();
        if ($fallback !== null) {
            $problems = [...$problems, ...($fallback === $provider
                ? ['AI_FALLBACK_PROVIDER must differ from AI_PROVIDER.']
                : $this->providerProblems($fallback, 'AI_FALLBACK_PROVIDER'))];
        }

        return $problems;
    }

    /** @return list<string> */
    private function providerProblems(string $provider, string $label = 'AI_PROVIDER'): array
    {
        if (! in_array($provider, $this->knownProviders(), true)) {
            return ["{$label} '{$provider}' is not supported."];
        }

        $problems = [];
        $settings = $this->settings($provider);
        $entry = (array) config("ai.providers.{$provider}");

        if ($settings['key'] === '') {
            $problems[] = "No API key for {$provider}: set ".strtoupper($provider).'_API_KEY (or AI_API_KEY for the active provider).';
        }
        if ($settings['model'] === '') {
            $problems[] = "No model for {$provider}: set AI_MODEL.";
        }
        if ($this->driverOf($provider) !== 'fake' && $settings['base_url'] === '') {
            $problems[] = "No base URL for {$provider}.";
        }
        if (! $this->capabilities($provider)->text) {
            $problems[] = "Model {$settings['model']} cannot generate text.";
        }
        if (config('ai.tool_calling_enabled') && ! $this->capabilities($provider)->tools && ($entry['capabilities']['tools'] ?? false) === false) {
            $problems[] = "Model {$settings['model']} on {$provider} has no tool calling; the assistant needs it (or set AI_TOOL_CALLING_ENABLED=false).";
        }
        if (($entry['free_tier'] ?? false) && app()->environment('production') && ! config('ai.allow_free_tier')) {
            $problems[] = "{$provider} is configured as a free-tier provider, which may use prompts for product improvement; set AI_ALLOW_FREE_TIER=true only after reviewing its data terms.";
        }

        return $problems;
    }
}
