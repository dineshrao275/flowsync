<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AiConfigurationService;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiResponseNormalizer;
use App\Services\Ai\Tools\AiToolRegistry;
use App\Services\Ai\Tools\GetTaskTool;
use App\Services\Ai\Tools\LeaveRequestsTool;
use App\Services\Ai\Tools\ListProjectsTool;
use App\Services\Ai\Tools\MyLeaveBalanceTool;
use App\Services\Ai\Tools\NavigationHelpTool;
use App\Services\Ai\Tools\ProjectSummaryTool;
use App\Services\Ai\Tools\SearchTasksTool;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // The catalogue of what the model may ask for. Adding a capability means adding a tool here, nowhere else.
        $this->app->singleton(AiToolRegistry::class, fn ($app) => new AiToolRegistry([
            $app->make(SearchTasksTool::class),
            $app->make(GetTaskTool::class),
            $app->make(ListProjectsTool::class),
            $app->make(ProjectSummaryTool::class),
            $app->make(MyLeaveBalanceTool::class),
            $app->make(LeaveRequestsTool::class),
            $app->make(NavigationHelpTool::class),
        ], $app->make(TenantContext::class), $app->make(TenantLimits::class)));
    }

    public function boot(): void
    {
        // Per user AND tenant: a model call costs money, and one noisy tenant must not starve another.
        RateLimiter::for('ai-chat', fn (Request $request) => Limit::perMinute((int) config('ai.requests_per_minute', 20))
            ->by(app(TenantContext::class)->currentId().':'.($request->user()?->id ?? $request->ip())));
    }
}
