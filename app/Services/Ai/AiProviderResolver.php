<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;

/**
 * Turns AI_PROVIDER into an adapter. This is the ONLY place that knows which classes exist;
 * switching providers is a config change because everything else asks for an `AiProvider`.
 */
final class AiProviderResolver
{
    private ?AiProvider $override = null;

    public function __construct(
        private readonly AiConfigurationService $config,
        private readonly AiResponseNormalizer $normalizer,
        private readonly AiCircuitBreaker $breaker,
    ) {}

    /** Tests (and local demos) pin a provider regardless of config. */
    public function swap(?AiProvider $provider): void
    {
        $this->override = $provider;
    }

    /** @throws AiConfigurationException when AI is off or the configuration is unusable */
    public function provider(): AiProvider
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $this->config->assertUsable();

        return $this->make($this->config->activeProvider());
    }

    /** The explicitly configured fallback, or null. Never chosen implicitly. */
    public function fallback(): ?AiProvider
    {
        $name = $this->config->fallbackProvider();

        return $name !== null && $this->override === null ? $this->make($name) : null;
    }

    public function isAvailable(): bool
    {
        if ($this->override !== null) {
            return true;
        }

        return $this->config->enabled() && $this->config->problems() === [];
    }

    private function make(string $name): AiProvider
    {
        $settings = $this->config->settings($name);
        $caps = $this->config->capabilities($name);

        return match ($this->config->driverOf($name)) {
            'gemini' => new GeminiProvider($name, $settings, $caps, $this->normalizer, $this->breaker),
            'openai' => new OpenAiCompatibleProvider($name, $settings, $caps, $this->normalizer, $this->breaker),
            'fake' => new FakeAiProvider($name, $settings['model'], $caps),
            default => throw new AiConfigurationException(["Provider '{$name}' has an unknown driver."]),
        };
    }
}
