<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * P2.2 — every response carries a correlation id, and an inbound one is only
 * honoured when it looks like one.
 */
class RequestIdTest extends TestCase
{
    public function test_a_response_always_carries_a_request_id(): void
    {
        $id = $this->getJson('/api/health')->headers->get('X-Request-Id');

        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{8,64}$/', $id);
    }

    public function test_a_well_formed_inbound_id_is_echoed(): void
    {
        $this->withHeader('X-Request-Id', 'client-req-12345')
            ->getJson('/api/health')
            ->assertHeader('X-Request-Id', 'client-req-12345');
    }

    public function test_a_malformed_inbound_id_is_replaced_not_trusted(): void
    {
        $hostile = "bad id\nwith newline and spaces";

        $id = $this->withHeader('X-Request-Id', $hostile)->getJson('/api/health')->headers->get('X-Request-Id');

        $this->assertNotSame($hostile, $id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{8,64}$/', $id);
    }

    public function test_two_requests_get_different_ids(): void
    {
        $a = $this->getJson('/api/health')->headers->get('X-Request-Id');
        $b = $this->getJson('/api/health')->headers->get('X-Request-Id');

        $this->assertNotSame($a, $b);
    }
}
