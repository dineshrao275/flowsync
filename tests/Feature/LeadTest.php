<?php

namespace Tests\Feature;

use App\Mail\LeadReceivedMail;
use App\Models\MarketingLead;
use App\Models\PlatformPermission;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_contact_requests_become_leads_and_notify_sales(): void
    {
        Mail::fake();
        PlatformSetting::set('website.sales_email', 'sales@flowsync.test');

        $this->postJson('/api/contact', [
            'name' => 'Dana',
            'email' => 'DANA@Example.COM',
            'company' => 'Initech',
            'message' => 'We would like a demo for fifty seats, please.',
            'source' => 'demo',
            'plan_interest' => 'Business',
        ])->assertOk()->assertJsonPath('message', 'Thanks — we will be in touch.');

        $lead = MarketingLead::where('email', 'dana@example.com')->firstOrFail();
        $this->assertSame('Dana', $lead->name);
        $this->assertSame('demo', $lead->source);
        $this->assertSame('new', $lead->status);
        $this->assertNotNull($lead->ip);

        Mail::assertQueued(LeadReceivedMail::class, fn ($mail) => $mail->leadName === 'Dana');
    }

    public function test_honeypot_answers_success_without_storing(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'Buy cheap watches, guaranteed real.',
            'website' => 'http://spam.example',
        ])->assertOk();

        $this->assertSame(0, MarketingLead::count());
        Mail::assertNothingQueued();
    }

    public function test_super_admin_works_the_inbox(): void
    {
        $this->postJson('/api/contact', [
            'name' => 'Dana',
            'email' => 'dana@example.com',
            'message' => 'We would like a demo for fifty seats, please.',
            'source' => 'demo',
        ])->assertOk();

        $this->postJson('/api/contact', [
            'name' => 'Eli',
            'email' => 'eli@example.com',
            'message' => 'Just saying hello to the team here.',
        ])->assertOk();

        $this->loginSuperAdmin();

        $this->getJson('/api/system/leads')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);

        $this->getJson('/api/system/leads?source=demo')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('leads.0.name', 'Dana');

        $this->getJson('/api/system/leads?q=eli')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $id = MarketingLead::where('email', 'dana@example.com')->value('id');

        $this->getJson("/api/system/leads/{$id}")
            ->assertOk()
            ->assertJsonPath('lead.message', 'We would like a demo for fifty seats, please.');

        $this->putJson("/api/system/leads/{$id}", ['status' => 'qualified'])
            ->assertOk()
            ->assertJsonPath('lead.status', 'qualified');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.lead_status_changed']);

        $this->putJson("/api/system/leads/{$id}", ['status' => 'archived'])
            ->assertStatus(422);

        $this->deleteJson("/api/system/leads/{$id}")->assertOk();
        $this->assertDatabaseMissing('marketing_leads', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.lead_deleted']);
    }

    public function test_platform_sync_access_command_is_additive(): void
    {
        $this->artisan('platform:sync-access')->assertSuccessful();
        // assertDatabaseHas resolves the default (tenant) connection, so read
        // central rows through the model like PlatformAccessTest does.
        $this->assertNotNull(PlatformPermission::where('slug', 'leads.view')->first());
    }

    public function test_contact_validation(): void
    {
        $this->postJson('/api/contact', [
            'name' => 'Dana',
            'email' => 'not-an-email',
            'message' => 'Long enough to pass the minimum length here.',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/contact', [
            'name' => 'Dana',
            'email' => 'dana@example.com',
            'message' => 'Too short',
        ])->assertStatus(422)->assertJsonValidationErrors('message');

        $this->postJson('/api/contact', [
            'name' => 'Dana',
            'email' => 'dana@example.com',
            'message' => 'Long enough to pass the minimum length here.',
            'source' => 'smoke-signal',
        ])->assertStatus(422)->assertJsonValidationErrors('source');

        $this->assertSame(0, MarketingLead::count());
    }
}
