<?php

namespace Tests\Feature;

use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F11.2 — the scrape endpoint is opt-in and token-guarded. */
class MetricsEndpointTest extends TestCase
{
    use IsolatesDatabase;

    public function test_it_does_not_exist_without_a_configured_token(): void
    {
        config(['observability.metrics_token' => null]);
        $this->get('/api/metrics')->assertNotFound();
    }

    public function test_it_needs_the_bearer_token_and_returns_prometheus_text(): void
    {
        config(['observability.metrics_token' => 'scrape-secret']);

        $this->get('/api/metrics')->assertUnauthorized();
        $this->get('/api/metrics', ['Authorization' => 'Bearer nope'])->assertUnauthorized();

        $res = $this->get('/api/metrics', ['Authorization' => 'Bearer scrape-secret'])->assertOk();
        $this->assertStringContainsString('flowsync_queue_failed', $res->getContent());
        $this->assertStringContainsString('flowsync_tenants{status="active"}', $res->getContent());
    }
}
