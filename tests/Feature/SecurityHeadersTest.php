<?php

namespace Tests\Feature;

use Tests\TestCase;

/** P8.5 - baseline headers and the report-only/enforce CSP toggle. */
class SecurityHeadersTest extends TestCase
{
    public function test_baseline_headers_are_present_and_csp_defaults_to_report_only(): void
    {
        $r = $this->getJson('/api/health');

        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $r->assertHeader('X-Frame-Options', 'DENY');
        $this->assertNotEmpty($r->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNull($r->headers->get('Content-Security-Policy'));
    }

    public function test_enforce_mode_swaps_the_header_name(): void
    {
        config(['security.csp.mode' => 'enforce']);

        $r = $this->getJson('/api/health');

        $this->assertNotEmpty($r->headers->get('Content-Security-Policy'));
        $this->assertNull($r->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_csp_allows_stripe_and_blocks_objects_and_framing(): void
    {
        $csp = $this->getJson('/api/health')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('https://js.stripe.com', $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_reverb_origin_joins_connect_src(): void
    {
        config(['reverb.apps.apps.0.options.host' => 'ws.example.test', 'reverb.apps.apps.0.options.port' => 8080]);

        $csp = $this->getJson('/api/health')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('wss://ws.example.test:8080', $csp);
    }

    public function test_csp_can_be_switched_off_and_headers_disabled(): void
    {
        config(['security.csp.mode' => 'off']);
        $r = $this->getJson('/api/health');
        $this->assertNull($r->headers->get('Content-Security-Policy-Report-Only'));
        $r->assertHeader('X-Content-Type-Options', 'nosniff');

        config(['security.headers.enabled' => false]);
        $this->assertFalse($this->getJson('/api/health')->headers->has('X-Content-Type-Options'));
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->assertFalse($this->getJson('/api/health')->headers->has('Strict-Transport-Security'));
        $this->assertTrue($this->getJson('https://localhost/api/health')->headers->has('Strict-Transport-Security'));
    }
}
