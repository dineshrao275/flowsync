<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SubscriptionPlan;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * H-7: platform audit rows carry a before/after diff of the changed keys
 * only, through the shared masking rules (`App\Support\AuditMask`).
 */
class PlatformAuditTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_a_settings_change_records_its_before_and_after(): void
    {
        $this->loginSuperAdmin();

        $this->putJson('/api/system/settings', [
            'app_name' => 'FlowSync Cloud',
            'public_registration' => true,
        ])->assertOk();

        $log = AuditLog::where('action', 'platform.settings_updated')->firstOrFail();

        $this->assertSame('platform_settings', $log->subject_type);
        $this->assertNotNull($log->actor_id);
        $this->assertNotNull($log->ip_address);

        // Only the keys that actually changed travel.
        $this->assertSame(['app_name', 'public_registration'], $log->data['keys']);
        $this->assertSame('FlowSync', $log->data['before']['app_name']);
        $this->assertSame('FlowSync Cloud', $log->data['after']['app_name']);
        $this->assertFalse($log->data['before']['public_registration']);
        $this->assertTrue($log->data['after']['public_registration']);
        $this->assertArrayNotHasKey('maintenance_mode', $log->data['before']);
        $this->assertArrayNotHasKey('maintenance_mode', $log->data['after']);
    }

    public function test_a_module_toggle_records_the_flip(): void
    {
        $this->loginSuperAdmin();

        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        $this->assertFalse($starter->hasModule('reports'));

        $this->putJson("/api/system/features/{$starter->id}", [
            'module' => 'reports',
            'enabled' => true,
        ])->assertOk();

        $log = AuditLog::where('action', 'plan.module_toggled')->firstOrFail();

        $this->assertSame('subscription_plans', $log->subject_type);
        $this->assertSame($starter->id, $log->subject_id);
        $this->assertSame('reports', $log->data['module']);
        $this->assertTrue($log->data['enabled']);
        $this->assertFalse($log->data['before']['enabled']);
        $this->assertTrue($log->data['after']['enabled']);
    }

    public function test_a_page_update_records_only_the_fields_that_changed(): void
    {
        $this->loginSuperAdmin();

        $id = $this->postJson('/api/system/pages', [
            'slug' => 'changelog',
            'title' => 'Changelog',
            'status' => 'draft',
            'content' => [['type' => 'text', 'heading' => 'Changelog', 'body' => 'What changed.']],
        ])->assertStatus(201)->json('page.id');

        $this->putJson("/api/system/pages/{$id}", [
            'slug' => 'changelog',
            'title' => 'Product Updates',
            'status' => 'draft',
            'content' => [['type' => 'text', 'heading' => 'Changelog', 'body' => 'What changed.']],
        ])->assertOk();

        $log = AuditLog::where('action', 'cms.page_updated')->firstOrFail();

        $this->assertSame('website_pages', $log->subject_type);
        $this->assertSame($id, $log->subject_id);
        $this->assertSame('changelog', $log->data['slug']);
        $this->assertSame(['title'], array_keys($log->data['before']));
        $this->assertSame('Changelog', $log->data['before']['title']);
        $this->assertSame('Product Updates', $log->data['after']['title']);
    }

    public function test_a_created_platform_admin_masks_the_email_but_keeps_the_name(): void
    {
        $this->loginSuperAdmin();

        $this->postJson('/api/system/users', [
            'name' => 'Second Admin',
            'email' => 'second@flowsync.test',
            'password' => 'password123',
        ])->assertCreated();

        $log = AuditLog::where('action', 'system.user_created')->firstOrFail();

        $this->assertNull($log->data['before']);
        $this->assertSame('Second Admin', $log->data['after']['name']);
        $this->assertSame('***', $log->data['after']['email']);
        $this->assertNotNull($log->subject_id);
    }
}
