<?php

namespace App\Http\Controllers\Hrms\Approval;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Approval\ApprovalDelegationRequest;
use App\Models\Hrms\Shared\ApprovalDelegation;
use App\Models\User;
use App\Services\Hrms\Approval\ChainTemplates;
use App\Services\Hrms\Shared\ApprovalDelegations;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Approval/HRMS — delegation of approvals for a window (P2.4).
 *
 * Self-service by default: the list is "given by me or to me" and a delegation
 * is created from the caller's own seat. `hrms.approvals.manage` widens the
 * list (`?scope=all`) and may name another person as the delegator.
 */
class ApprovalDelegationController extends Controller
{
    public function __construct(
        private readonly ApprovalDelegations $delegations,
        private readonly ChainTemplates $templates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApprovalDelegation::class);
        $user = $request->user();

        $query = ApprovalDelegation::query()->with(['from:id,name', 'to:id,name'])->orderByDesc('id');

        if ($request->query('scope') === 'all') {
            $this->authorize('viewAll', ApprovalDelegation::class);
        } else {
            $query->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id));
        }

        return response()->json([
            'delegations' => $query->limit(200)->get()->map(fn (ApprovalDelegation $d): array => $this->present($d, $user)),
            'domains' => collect($this->templates->domains())->map(fn ($d, $key) => ['value' => $key, 'label' => $d['label']])->values(),
        ]);
    }

    public function store(ApprovalDelegationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->authorize('create', [ApprovalDelegation::class, $data['from_user_id'] ?? null]);

        $from = User::query()->find($data['from_user_id'] ?? $request->user()->id);
        $to = User::query()->find($data['to_user_id']);

        if ($from === null || $to === null) {
            throw ValidationException::withMessages(['to_user_id' => 'Choose a person from this workspace.']);
        }

        $unknown = array_diff($data['domains'] ?? [], array_keys($this->templates->domains()));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['domains' => 'Unknown approval domain.']);
        }

        $delegation = $this->delegations->create(
            $from,
            $to,
            Carbon::parse($data['starts_at']),
            Carbon::parse($data['ends_at']),
            $data['domains'] ?? null,
            $data['reason'] ?? null,
            $request->user(),
        );

        return response()->json(['message' => 'Delegation saved.', 'delegation' => $this->present($delegation->load(['from:id,name', 'to:id,name']), $request->user())], 201);
    }

    public function destroy(Request $request, ApprovalDelegation $delegation): JsonResponse
    {
        $this->authorize('revoke', $delegation);

        $this->delegations->revoke($delegation, $request->user());

        return response()->json(['message' => 'Delegation revoked.']);
    }

    /** People a delegation may hand approvals to — a prefix search, never the whole directory. */
    public function targets(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($q) < 2, 422, 'Type at least 2 characters.');

        $users = Like::any(User::query()->where('id', '!=', $request->user()->id), ['name', 'email'], $q)
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email']);

        return response()->json(['users' => $users]);
    }

    /** @return array<string, mixed> */
    private function present(ApprovalDelegation $d, User $viewer): array
    {
        return [
            'id' => $d->id,
            'from' => ['id' => $d->from_user_id, 'name' => $d->from?->name],
            'to' => ['id' => $d->to_user_id, 'name' => $d->to?->name],
            'starts_at' => $d->starts_at->toIso8601String(),
            'ends_at' => $d->ends_at->toIso8601String(),
            'domains' => $d->domains,
            'reason' => $d->reason,
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'active' => $d->revoked_at === null && $d->starts_at->isPast() && $d->ends_at->isFuture(),
            'can_revoke' => $viewer->can('revoke', $d),
        ];
    }
}
