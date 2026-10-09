<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\UserNotification;
use App\Support\TenantDatabaseManager;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** FB-7 — tenant admins raise tickets; the Super Admin works them from one inbox. */
class SupportTicketTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function logout(): void
    {
        $this->postJson('/api/auth/logout');
    }

    private function raise(array $over = []): int
    {
        return $this->postJson('/api/support/tickets', $over + [
            'subject' => 'Cannot export payroll', 'category' => 'technical', 'priority' => 'high',
            'body' => 'The payroll export fails with a 500 since this morning.',
        ])->assertCreated()->json('ticket.id');
    }

    public function test_a_tenant_admin_raises_a_ticket_and_sees_it_in_their_list(): void
    {
        $this->login('admin@flowsync.test');
        $id = $this->raise();

        $ticket = SupportTicket::findOrFail($id);
        $this->assertSame(Tenant::where('slug', 'acme')->value('id'), $ticket->tenant_id);
        $this->assertSame('open', $ticket->status);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertTrue(AuditLog::where('action', 'support.ticket_created')->where('subject_id', $id)->exists());

        $this->getJson('/api/support/tickets')->assertOk()->assertJsonPath('tickets.0.reference', $ticket->reference());
        $this->getJson("/api/support/tickets/{$id}")->assertOk()->assertJsonCount(1, 'ticket.messages');
    }

    public function test_only_users_with_the_support_permission_can_use_the_desk(): void
    {
        $this->login('viewer@flowsync.test');

        $this->getJson('/api/support/tickets')->assertForbidden();
        $this->postJson('/api/support/tickets', ['subject' => 'x', 'category' => 'other', 'priority' => 'low', 'body' => 'long enough body'])->assertForbidden();
    }

    public function test_a_ticket_is_validated(): void
    {
        $this->login('admin@flowsync.test');

        $this->postJson('/api/support/tickets', ['subject' => '', 'category' => 'nonsense', 'priority' => 'asap', 'body' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['subject', 'category', 'priority', 'body']);
    }

    public function test_the_other_tenants_ticket_does_not_exist_for_you(): void
    {
        $globex = Tenant::where('slug', 'globex')->firstOrFail();
        $foreign = SupportTicket::create([
            'tenant_id' => $globex->id, 'created_by_user_id' => 1, 'created_by_name' => 'G', 'created_by_email' => 'owner@globex.test',
            'subject' => 'Globex only', 'category' => 'other', 'priority' => 'low', 'status' => 'open', 'last_activity_at' => now(),
        ]);

        $this->login('admin@flowsync.test');

        $this->getJson("/api/support/tickets/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/support/tickets/{$foreign->id}/messages", ['body' => 'hello'])->assertNotFound();
        $this->postJson("/api/support/tickets/{$foreign->id}/close")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/support/tickets')->json('tickets'));
    }

    public function test_staff_see_every_tenant_and_a_reply_reaches_the_requester_but_a_note_does_not(): void
    {
        $this->login('admin@flowsync.test');
        $id = $this->raise(['priority' => 'urgent']);
        $this->logout();

        $this->login('superadmin@flowsync.test');
        $this->getJson('/api/system/support/tickets')->assertOk()
            ->assertJsonPath('tickets.0.id', $id)->assertJsonPath('tickets.0.tenant.slug', 'acme')->assertJsonPath('counts.open', 1);

        $this->postJson("/api/system/support/tickets/{$id}/messages", ['body' => 'Looks like a bad template, checking.', 'internal' => true])->assertCreated();
        $this->postJson("/api/system/support/tickets/{$id}/messages", ['body' => 'We are looking into it now.'])->assertCreated()
            ->assertJsonPath('ticket.status', 'waiting_on_customer');
        $this->logout();

        $this->login('admin@flowsync.test');
        $bodies = array_column($this->getJson("/api/support/tickets/{$id}")->json('ticket.messages'), 'body');
        $this->assertContains('We are looking into it now.', $bodies);
        $this->assertNotContains('Looks like a bad template, checking.', $bodies);

        // The requester got an in-app notification inside their tenant DB.
        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        $types = app(TenantDatabaseManager::class)->using($acme, fn () => UserNotification::pluck('type')->all());
        $this->assertContains('support.reply', $types);
    }

    public function test_a_customer_reply_reopens_a_resolved_ticket_but_a_closed_one_stays_closed(): void
    {
        $this->login('admin@flowsync.test');
        $id = $this->raise();
        $this->logout();

        $this->login('superadmin@flowsync.test');
        $this->putJson("/api/system/support/tickets/{$id}", ['status' => 'resolved'])->assertOk()->assertJsonPath('ticket.status', 'resolved');
        $this->logout();

        $this->login('admin@flowsync.test');
        $this->postJson("/api/support/tickets/{$id}/messages", ['body' => 'Still happening, sorry.'])->assertCreated()
            ->assertJsonPath('ticket.status', 'open');

        $this->postJson("/api/support/tickets/{$id}/close")->assertOk()->assertJsonPath('ticket.status', 'closed');
        $this->postJson("/api/support/tickets/{$id}/messages", ['body' => 'one more thing'])->assertUnprocessable();
    }

    public function test_staff_can_assign_and_reprioritise_and_filter(): void
    {
        $this->login('admin@flowsync.test');
        $a = $this->raise(['priority' => 'low']);
        $this->raise(['subject' => 'Billing question', 'category' => 'billing', 'priority' => 'urgent']);
        $this->logout();

        $this->login('superadmin@flowsync.test');
        $meId = $this->getJson('/api/auth/me')->json('user.id');
        $this->putJson("/api/system/support/tickets/{$a}", ['assigned_to' => $meId, 'priority' => 'high'])->assertOk()
            ->assertJsonPath('ticket.priority', 'high')->assertJsonPath('ticket.assigned_to', $meId);

        $this->assertSame([$a], array_column($this->getJson('/api/system/support/tickets?assigned=me')->json('tickets'), 'id'));
        $this->assertCount(1, $this->getJson('/api/system/support/tickets?assigned=none')->json('tickets'));
        // Urgent sorts ahead of everything else.
        $this->assertSame('urgent', $this->getJson('/api/system/support/tickets')->json('tickets.0.priority'));
        $this->assertCount(1, $this->getJson('/api/system/support/tickets?q=billing')->json('tickets'));
    }

    public function test_the_staff_inbox_is_super_admin_only(): void
    {
        $this->login('admin@flowsync.test');

        $this->getJson('/api/system/support/tickets')->assertForbidden();
    }
}
