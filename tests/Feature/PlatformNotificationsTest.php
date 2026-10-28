<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PlatformLeadReceived;
use App\Events\PlatformQueueJobFailed;
use App\Events\PlatformSupportTicketEvent;
use App\Models\MarketingLead;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Support\Facades\Event;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class PlatformNotificationsTest extends TestCase
{
    use IsolatesDatabase;

    public function test_super_admin_can_retrieve_badge_counts(): void
    {
        $this->loginAsSuperAdmin();

        MarketingLead::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'message' => 'Hello',
            'status' => MarketingLead::STATUS_NEW,
        ]);

        $tenant = Tenant::first();
        $user = User::first();

        SupportTicket::create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $user->id,
            'created_by_name' => $user->name,
            'created_by_email' => $user->email,
            'subject' => 'Need help',
            'category' => 'technical',
            'priority' => 'high',
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        $response = $this->getJson('/api/system/badge-counts');

        $response->assertOk()
            ->assertJsonStructure(['leads', 'support', 'queue']);

        $this->assertGreaterThanOrEqual(1, $response->json('leads'));
        $this->assertGreaterThanOrEqual(1, $response->json('support'));
    }

    public function test_tenant_user_cannot_access_platform_badge_counts(): void
    {
        $this->loginTenantUser('admin@flowsync.test');

        $this->getJson('/api/system/badge-counts')->assertForbidden();
    }

    public function test_contact_form_submits_and_dispatches_lead_received(): void
    {
        Event::fake([PlatformLeadReceived::class]);

        $this->postJson('/api/contact', [
            'name' => 'Alice Wonder',
            'email' => 'alice@wonderland.test',
            'company' => 'Wonderland Co',
            'message' => 'Interested in enterprise plan',
            'source' => 'contact',
        ])->assertOk();

        Event::assertDispatched(PlatformLeadReceived::class, function ($event) {
            return $event->lead->email === 'alice@wonderland.test';
        });
    }

    private function loginAsSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    private function loginTenantUser(string $email): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();
    }
}
