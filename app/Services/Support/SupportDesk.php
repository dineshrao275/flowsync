<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PlatformAudit;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Rules of the support desk, shared by the tenant-facing and the Super Admin
 * endpoints so the two sides can never disagree about what a status means.
 *
 * Tickets live in the system DB: platform staff never need a tenant database
 * to answer one. Internal notes are staff-only and are filtered out wherever a
 * tenant can read a thread.
 */
class SupportDesk
{
    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly TenantDatabaseManager $dbm,
    ) {}

    /** @param array{subject: string, category: string, priority: string, body: string} $data */
    public function open(Tenant $tenant, User $author, array $data): SupportTicket
    {
        return DB::connection($this->dbm->centralConnectionName())->transaction(function () use ($tenant, $author, $data): SupportTicket {
            $ticket = SupportTicket::create([
                'tenant_id' => $tenant->id,
                'created_by_user_id' => $author->id,
                'created_by_name' => $author->name,
                'created_by_email' => $author->email,
                'subject' => $data['subject'],
                'category' => $data['category'],
                'priority' => $data['priority'],
                'status' => SupportTicket::STATUS_OPEN,
                'last_activity_at' => now(),
            ]);

            $ticket->messages()->create([
                'author_type' => 'tenant', 'author_user_id' => $author->id,
                'author_name' => $author->name, 'body' => $data['body'],
            ]);

            $this->audit->record(null, 'support.ticket_created', SupportTicket::class, $ticket->id, [
                'tenant_id' => $tenant->id, 'reference' => $ticket->reference(),
                'category' => $ticket->category, 'priority' => $ticket->priority,
            ]);

            return $ticket;
        });
    }

    public function replyAsTenant(SupportTicket $ticket, User $author, string $body): SupportTicketMessage
    {
        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            throw ValidationException::withMessages(['form' => 'This ticket is closed. Open a new ticket if the problem continues.']);
        }

        $message = $ticket->messages()->create([
            'author_type' => 'tenant', 'author_user_id' => $author->id, 'author_name' => $author->name, 'body' => $body,
        ]);

        // A reply from the customer puts it back in front of staff.
        $ticket->forceFill([
            'status' => SupportTicket::STATUS_OPEN, 'resolved_at' => null, 'last_activity_at' => now(),
        ])->save();

        return $message;
    }

    public function replyAsStaff(SupportTicket $ticket, User $staff, string $body, bool $internal): SupportTicketMessage
    {
        if ($ticket->status === SupportTicket::STATUS_CLOSED && ! $internal) {
            throw ValidationException::withMessages(['form' => 'This ticket is closed.']);
        }

        $message = $ticket->messages()->create([
            'author_type' => 'staff', 'author_user_id' => $staff->id, 'author_name' => $staff->name,
            'body' => $body, 'is_internal' => $internal,
        ]);

        if ($internal) {
            return $message; // a note changes nothing the customer can see
        }

        $ticket->forceFill([
            'status' => $ticket->isOpen() ? SupportTicket::STATUS_WAITING : $ticket->status,
            'assigned_to' => $ticket->assigned_to ?? $staff->id,
            'last_activity_at' => now(),
        ])->save();

        $this->notifyRequester($ticket, 'support.reply', ['snippet' => str($body)->limit(120)->toString()]);

        return $message;
    }

    public function setStatus(SupportTicket $ticket, string $status, ?User $actor = null): SupportTicket
    {
        if ($ticket->status === $status) {
            return $ticket;
        }

        $from = $ticket->status;
        $ticket->forceFill([
            'status' => $status,
            'resolved_at' => $status === SupportTicket::STATUS_RESOLVED ? now() : ($status === SupportTicket::STATUS_CLOSED ? $ticket->resolved_at : null),
            'closed_at' => $status === SupportTicket::STATUS_CLOSED ? now() : null,
            'last_activity_at' => now(),
        ])->save();

        $this->audit->record(null, 'support.ticket_status_changed', SupportTicket::class, $ticket->id, [
            'tenant_id' => $ticket->tenant_id, 'from' => $from, 'to' => $status,
            'by' => $actor ? 'staff' : 'tenant',
        ], $actor?->is_super_admin ? $actor->id : null);

        if ($actor?->is_super_admin && in_array($status, [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED], true)) {
            $this->notifyRequester($ticket, 'support.'.$status);
        }

        return $ticket;
    }

    /** In-app notification to whoever raised the ticket, inside their own tenant DB. Never blocks the reply. */
    private function notifyRequester(SupportTicket $ticket, string $type, array $extra = []): void
    {
        try {
            $tenant = $ticket->tenant;
            if (! $tenant?->isProvisioned() || ! $ticket->created_by_user_id) {
                return;
            }
            $this->dbm->using($tenant, function () use ($ticket, $type, $extra): void {
                $user = User::find($ticket->created_by_user_id);
                if ($user) {
                    app(NotificationService::class)->notify($user, $type, [
                        'ticket_id' => $ticket->id, 'reference' => $ticket->reference(), 'subject' => $ticket->subject,
                    ] + $extra);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Support notification failed.', ['ticket' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }
}
