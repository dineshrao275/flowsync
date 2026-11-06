<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AiConfigurationService;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiResponseNormalizer;
use Illuminate\Support\ServiceProvider;

/** Singletons for the provider-agnostic AI layer. Nothing here talks to a provider until a request asks. */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiConfigurationService::class);
        $this->app->singleton(AiResponseNormalizer::class);
        // One resolver per process so a test (or a demo) can `swap()` a provider for every consumer.
        $this->app->singleton(AiProviderResolver::class);
    }
}
