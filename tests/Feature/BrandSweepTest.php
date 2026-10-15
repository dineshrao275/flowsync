<?php

namespace Tests\Feature;

use Tests\IsolatesDatabase;
use Tests\TestCase;

class BrandSweepTest extends TestCase
{
    use IsolatesDatabase;

    public function test_application_name_and_storage_prefix_are_configured_properly(): void
    {
        $this->assertSame('FlowSync', config('app.name'));
        $this->assertSame('flowsync', config('app.storage_prefix'));
    }

    public function test_spa_view_renders_flowsync_branding_and_config(): void
    {
        $response = $this->get('/app/login');
        $response->assertOk();
        $response->assertSee('<title>FlowSync Admin</title>', false);
        $response->assertSee('__FLOWSYNC_CONFIG__', false);
        $response->assertSee('storagePrefix: "flowsync"', false);
    }

    public function test_no_unwanted_legacy_boilerplate_in_app_views(): void
    {
        $html = view('app')->render();
        $this->assertStringNotContainsString('Laravel has an incredibly rich ecosystem', $html);
        $this->assertStringContainsString('FlowSync', $html);
    }
}
