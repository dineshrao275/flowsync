<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AiConfigurationService;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;
use Tests\TestCase;

class AiConfigurationTest extends TestCase
{
    private function useProvider(string $provider, array $extra = []): void
    {
        config(array_merge([
            'ai.enabled' => true,
            'ai.provider' => $provider,
            'ai.providers.gemini.key' => 'g-key',
            'ai.providers.groq.key' => 'q-key',
        ], $extra));
    }

    public function test_disabled_ai_is_never_validated_and_cannot_be_resolved(): void
    {
        config(['ai.enabled' => false, 'ai.provider' => 'nonsense']);

        $this->assertFalse(app(AiProviderResolver::class)->isAvailable());
        $this->expectException(AiConfigurationException::class);
        $this->expectExceptionMessage('AI is disabled');
        app(AiProviderResolver::class)->provider();
    }

    public function test_the_resolver_picks_the_adapter_from_the_environment_setting(): void
    {
        $this->useProvider('gemini');
        $this->assertInstanceOf(GeminiProvider::class, app(AiProviderResolver::class)->provider());

        $this->useProvider('groq');
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, app(AiProviderResolver::class)->provider());

        $this->useProvider('fake');
        $this->assertInstanceOf(FakeAiProvider::class, app(AiProviderResolver::class)->provider());
    }

    public function test_model_and_credentials_follow_the_generic_settings_for_the_active_provider_only(): void
    {
        $this->useProvider('groq', ['ai.model' => 'openai/gpt-oss-120b', 'ai.providers.groq.key' => '', 'ai.api_key' => 'generic', 'ai.fallback_provider' => 'gemini']);

        $cfg = app(AiConfigurationService::class);
        $this->assertSame('generic', $cfg->settings()['key']);
        $this->assertSame('openai/gpt-oss-120b', $cfg->settings()['model']);

        // The fallback never inherits the generic key or model of the active provider.
        $this->assertSame('g-key', $cfg->settings('gemini')['key']);
        $this->assertSame(config('ai.providers.gemini.default_model'), $cfg->settings('gemini')['model']);
    }

    public function test_every_problem_is_reported_at_once(): void
    {
        $this->useProvider('groq', ['ai.providers.groq.key' => '', 'ai.timeout_seconds' => 0, 'ai.max_retries' => 9]);

        try {
            app(AiConfigurationService::class)->validate();
            $this->fail('Expected a configuration exception.');
        } catch (AiConfigurationException $e) {
            $this->assertCount(3, $e->problems);
            $this->assertStringContainsString('No API key for groq', $e->problems[0]);
        }
    }

    public function test_an_unknown_provider_fails_clearly(): void
    {
        $this->useProvider('openai-typo');

        $this->assertStringContainsString("'openai-typo' is not supported", app(AiConfigurationService::class)->problems()[0]);
    }

    public function test_a_free_tier_provider_is_refused_in_production_until_acknowledged(): void
    {
        $this->useProvider('gemini');
        $this->app['env'] = 'production';

        $this->assertStringContainsString('free-tier', implode(' ', app(AiConfigurationService::class)->problems()));

        config(['ai.allow_free_tier' => true]);
        $this->assertSame([], app(AiConfigurationService::class)->problems());
    }

    public function test_capabilities_are_corrected_per_model_and_narrowed_by_the_feature_switches(): void
    {
        $this->useProvider('groq', ['ai.model' => 'llama-3.3-70b-versatile']);
        $cfg = app(AiConfigurationService::class);
        $this->assertTrue($cfg->capabilities()->parallelTools);
        $this->assertFalse($cfg->capabilities()->jsonSchemaOutput);

        config(['ai.model' => 'openai/gpt-oss-120b']);
        $this->assertFalse($cfg->capabilities()->parallelTools);
        $this->assertTrue($cfg->capabilities()->jsonSchemaOutput);

        config(['ai.streaming_enabled' => false, 'ai.tool_calling_enabled' => false]);
        $this->assertFalse($cfg->capabilities()->streaming);
        $this->assertFalse($cfg->capabilities()->tools);
    }

    public function test_a_fallback_must_be_explicit_and_different_from_the_active_provider(): void
    {
        $this->useProvider('gemini');
        $this->assertNull(app(AiProviderResolver::class)->fallback());

        config(['ai.fallback_provider' => 'gemini']);
        $this->assertStringContainsString('must differ', implode(' ', app(AiConfigurationService::class)->problems()));

        config(['ai.fallback_provider' => 'groq']);
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, app(AiProviderResolver::class)->fallback());
    }

    public function test_the_application_still_boots_and_serves_requests_with_ai_off(): void
    {
        config(['ai.enabled' => false]);

        $this->get('/robots.txt')->assertOk();
    }
}
