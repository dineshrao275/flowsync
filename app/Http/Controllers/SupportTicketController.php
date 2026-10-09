<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Services\Support\SupportDesk;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tenant side of the support desk: raise a ticket, follow it, reply, close. Own tenant only. */
class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportDesk $desk,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::where('tenant_id', $this->tenantId())
            ->when($request->query('status') === 'open', fn ($q) => $q->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_PROGRESS, SupportTicket::STATUS_WAITING]))
            ->when($request->query('status') === 'closed', fn ($q) => $q->whereIn('status', [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED]))
            ->orderByDesc('last_activity_at')->limit(100)->get();

        return response()->json(['tickets' => $tickets->map(fn (SupportTicket $t) => $this->present($t))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        $ticket = $this->desk->open(Tenant::findOrFail($this->tenantId()), $request->user(), $data);

        return response()->json(['message' => 'Ticket '.$ticket->reference().' created.', 'ticket' => $this->present($ticket, true)], 201);
    }

    public function show(int $ticket): JsonResponse
    {
        return response()->json(['ticket' => $this->present($this->own($ticket), true)]);
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $record = $this->own($ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        $this->desk->replyAsTenant($record, $request->user(), $data['body']);

        return response()->json(['message' => 'Reply sent.', 'ticket' => $this->present($record->refresh(), true)], 201);
    }

    public function close(Request $request, int $ticket): JsonResponse
    {
        $record = $this->desk->setStatus($this->own($ticket), SupportTicket::STATUS_CLOSED);

        return response()->json(['message' => 'Ticket closed.', 'ticket' => $this->present($record, true)]);
    }

    private function tenantId(): int
    {
        $id = $this->tenant->currentId();
        abort_unless($id, 404, 'No active tenant context.');

        return $id;
    }

    /** A ticket of another tenant is a 404, not a 403: its existence is not ours to confirm. */
    private function own(int $id): SupportTicket
    {
        return SupportTicket::where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(SupportTicket $t, bool $withMessages = false): array
    {
        return [
            'id' => $t->id, 'reference' => $t->reference(), 'subject' => $t->subject,
            'category' => $t->category, 'priority' => $t->priority, 'status' => $t->status,
            'created_by_name' => $t->created_by_name, 'created_at' => $t->created_at?->toIso8601String(),
            'last_activity_at' => $t->last_activity_at?->toIso8601String(),
            ...($withMessages ? ['messages' => $t->messages()->where('is_internal', false)->orderBy('id')->get()->map(fn ($m) => [
                'id' => $m->id, 'author_type' => $m->author_type, 'author_name' => $m->author_name,
                'body' => $m->body, 'created_at' => $m->created_at?->toIso8601String(),
            ])] : []),
        ];
    }
}
