<?php

namespace App\Services\Hrms\Shared;

use App\Models\Hrms\Shared\ApprovalDelegation;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Shared/HRMS — approval delegations (P2.4): "from" hands their approvals to
 * "to" for a window, optionally limited to some domains.
 *
 * A delegate acts *as* the delegator (audited as `approval.delegated_*`, the
 * step records `acted_for_user_id`); it never widens who may approve a request
 * the delegate made themselves, and a delegation does not chain (A→B and B→C
 * does not let C act for A).
 */
class ApprovalDelegations
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * @param  list<string>|null  $domains  null/empty = every domain
     *
     * @throws ValidationException
     */
    public function create(User $from, User $to, Carbon $startsAt, Carbon $endsAt, ?array $domains, ?string $reason, ?User $actor): ApprovalDelegation
    {
        if ((int) $from->id === (int) $to->id) {
            throw ValidationException::withMessages(['to_user_id' => 'You cannot delegate to yourself.']);
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'The window must end after it starts.']);
        }

        $delegation = ApprovalDelegation::create([
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'domains' => $domains === [] ? null : $domains,
            'reason' => $reason,
            'created_by_user_id' => $actor?->id,
        ]);

        $this->audit->log($delegation, 'approval.delegation_created', null, [
            'from_user_id' => $from->id, 'to_user_id' => $to->id,
            'starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(),
        ], $actor);

        return $delegation;
    }

    public function revoke(ApprovalDelegation $delegation, ?User $actor): ApprovalDelegation
    {
        if ($delegation->revoked_at === null) {
            $delegation->update(['revoked_at' => now()]);
            $this->audit->log($delegation, 'approval.delegation_revoked', null, ['revoked' => true], $actor);
        }

        return $delegation;
    }

    /**
     * Delegations in force right now that hand work TO this user.
     *
     * @return Collection<int, ApprovalDelegation>
     */
    public function activeTo(User $user): Collection
    {
        return ApprovalDelegation::query()->active()->where('to_user_id', $user->id)->get();
    }

    /**
     * Users who may be acted for by $user on an approval of the given domain.
     *
     * @return list<int>
     */
    public function delegatorIds(User $user, ?string $domain): array
    {
        return $this->activeTo($user)
            ->filter(fn (ApprovalDelegation $d) => $d->coversDomain($domain))
            ->pluck('from_user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
