<?php

namespace Tests\Feature;

use App\Http\Middleware\PlatformMaintenance;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase 0 of the GitHub App plan: provider callbacks must reach the app during real
 * traffic (no browser session, no CSRF) and during maintenance mode.
 */
class GitIntegrationSafetyTest extends TestCase
{
    use IsolatesDatabase;

    public function test_git_callbacks_are_exempt_from_csrf_but_admin_management_is_not(): void
    {
        $middleware = app(ValidateCsrfToken::class);
        $exempt = new ReflectionMethod($middleware, 'inExceptArray');

        foreach ([
            '/api/integrations/git/github/webhook',
            '/api/integrations/github/1',
        ] as $path) {
            $this->assertTrue(
                $exempt->invoke($middleware, Request::create($path, 'POST')),
                "$path must not require a CSRF token"
            );
        }

        // The public prefix must not leak into tenant session routes.
        foreach ([
            '/api/integrations/git/connections',
            '/api/integrations/git/github/setup',
            '/api/projects/1/repositories',
            '/api/github-integration',
        ] as $path) {
            $this->assertFalse(
                $exempt->invoke($middleware, Request::create($path, 'POST')),
                "$path must stay CSRF-protected"
            );
        }
    }

    public function test_git_callbacks_stay_reachable_during_maintenance(): void
    {
        PlatformSetting::set('maintenance_mode', '1');
        Cache::forget('platform.maintenance');

        $middleware = app(PlatformMaintenance::class);
        $next = fn () => response('ok');

        foreach ([
            '/api/integrations/git/github/webhook',
            '/api/integrations/github/1',
        ] as $path) {
            $response = $middleware->handle(Request::create($path, 'POST'), $next);
            $this->assertSame(200, $response->getStatusCode(), "$path must stay open during maintenance");
        }

        // Tenant actions wait for maintenance to end rather than changing data mid-freeze.
        foreach ([
            '/api/dashboard',
            '/api/integrations/git/connections',
            '/api/integrations/git/github/setup',
        ] as $path) {
            $blocked = $middleware->handle(Request::create($path, 'GET'), $next);
            $this->assertSame(503, $blocked->getStatusCode(), "$path must be refused during maintenance");
            $this->assertSame('maintenance', $blocked->getData(true)['code']);
        }

        PlatformSetting::set('maintenance_mode', '0');
        Cache::forget('platform.maintenance');
    }

    public function test_git_config_exists_and_is_safe_by_default(): void
    {
        foreach ([
            'git.enabled',
            'git.providers.github.app_id',
            'git.providers.github.slug',
            'git.providers.github.private_key',
            'git.providers.github.private_key_path',
            'git.providers.github.webhook_secret',
            'git.providers.github.api_url',
            'git.token_cache_ttl',
            'git.http_timeout',
            'git.per_page',
            'git.max_keys_per_event',
            'git.max_commits_per_push',
            'git.allow_cross_project',
            'git.smart_commits',
            'git.retention_days',
        ] as $key) {
            $this->assertTrue(config()->has($key), "config/$key must exist");
        }

        $this->assertIsBool(config('git.enabled'));
        $this->assertFalse(config('git.allow_cross_project'));
        $this->assertFalse(config('git.smart_commits'));
        $this->assertSame(100, config('git.per_page'));
        $this->assertSame(20, config('git.max_keys_per_event'));
        $this->assertSame(20, config('git.max_commits_per_push'));
    }
}
