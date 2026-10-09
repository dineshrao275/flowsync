<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Services\Support\SupportDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Super Admin inbox: every tenant's tickets, internal notes, assignment and status. */
class SystemSupportController extends Controller
{
    public function __construct(private readonly SupportDesk $desk) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...SupportTicket::STATUSES, 'open_all'])],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'tenant_id' => ['nullable', 'integer'],
            'assigned' => ['nullable', Rule::in(['me', 'none'])],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SupportTicket::with(['tenant:id,name,slug', 'assignee:id,name'])
            ->when(($data['status'] ?? null) === 'open_all', fn ($q) => $q->whereNotIn('status', [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED]))
            ->when(! empty($data['status']) && $data['status'] !== 'open_all', fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['priority']), fn ($q) => $q->where('priority', $data['priority']))
            ->when(! empty($data['tenant_id']), fn ($q) => $q->where('tenant_id', $data['tenant_id']))
            ->when(($data['assigned'] ?? null) === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when(($data['assigned'] ?? null) === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when(! empty($data['q']), fn ($q) => $q->where(fn ($w) => $w->where('subject', 'like', "%{$data['q']}%")->orWhere('created_by_email', 'like', "%{$data['q']}%")))
            // Urgent first, then oldest activity first: what has waited longest on us.
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('last_activity_at');

        $page = $query->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'tickets' => $page->getCollection()->map(fn (SupportTicket $t) => $this->present($t))->values(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'counts' => SupportTicket::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        return response()->json(['ticket' => $this->present($ticket->load(['tenant:id,name,slug', 'assignee:id,name']), true)]);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 'internal' => ['sometimes', 'boolean']]);

        $this->desk->replyAsStaff($ticket, $request->user(), $data['body'], (bool) ($data['internal'] ?? false));

        return response()->json(['message' => 'Sent.', 'ticket' => $this->present($ticket->refresh()->load(['tenant:id,name,slug', 'assignee:id,name']), true)], 201);
    }

    public function update(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_super_admin', true)],
        ]);

        if (isset($data['priority']) || array_key_exists('assigned_to', $data)) {
            $ticket->update(array_intersect_key($data, array_flip(['priority', 'assigned_to'])) + ['last_activity_at' => now()]);
        }
        if (isset($data['status'])) {
            $this->desk->setStatus($ticket, $data['status'], $request->user());
        }

        return response()->json(['message' => 'Ticket updated.', 'ticket' => $this->present($ticket->refresh()->load(['tenant:id,name,slug', 'assignee:id,name']))]);
    }

    /** @return array<string, mixed> */
    private function present(SupportTicket $t, bool $withMessages = false): array
    {
        return [
            'id' => $t->id, 'reference' => $t->reference(), 'subject' => $t->subject,
            'category' => $t->category, 'priority' => $t->priority, 'status' => $t->status,
            'tenant' => $t->tenant ? ['id' => $t->tenant->id, 'name' => $t->tenant->name, 'slug' => $t->tenant->slug] : null,
            'created_by_name' => $t->created_by_name, 'created_by_email' => $t->created_by_email,
            'assigned_to' => $t->assigned_to, 'assignee' => $t->assignee?->name,
            'created_at' => $t->created_at?->toIso8601String(), 'last_activity_at' => $t->last_activity_at?->toIso8601String(),
            ...($withMessages ? ['messages' => $t->messages()->orderBy('id')->get()->map(fn ($m) => [
                'id' => $m->id, 'author_type' => $m->author_type, 'author_name' => $m->author_name,
                'body' => $m->body, 'is_internal' => $m->is_internal, 'created_at' => $m->created_at?->toIso8601String(),
            ])] : []),
        ];
    }
}
