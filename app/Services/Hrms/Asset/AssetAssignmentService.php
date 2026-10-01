<?php

namespace App\Services\Hrms\Asset;

use App\Enums\Hrms\AssetAssignmentStatus;
use App\Enums\Hrms\AssetStatus;
use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asset/HRMS — handovers: who holds what, since when, and their receipts.
 *
 * Assign opens the row and moves the register; acknowledge is the
 * employee's signature (and only theirs — HR cannot receipt on someone's
 * behalf); return closes the row and restores the register with the
 * condition it came back in. The exit clearance reads the `active` rows,
 * which is why this phase matters to offboarding: a handover left active
 * blocks its holder's exit until it closes.
 */
class AssetAssignmentService
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Hand an asset over: available-only (a second assignment, a repair
     * bay, a retirement and a loss all refuse with the reason named), the
     * register follows the row, and the employee is told to acknowledge.
     *
     * @throws ValidationException unless the asset is available
     */
    public function assign(Asset $asset, Employee $employee, string $conditionOut, ?User $actor = null): AssetAssignment
    {
        if ($asset->status !== AssetStatus::Available) {
            throw ValidationException::withMessages(['form' => "That asset is {$asset->status->value} — only an available asset changes hands."]);
        }

        return DB::transaction(function () use ($asset, $employee, $conditionOut, $actor): AssetAssignment {
            $assignment = AssetAssignment::create([
                'asset_id' => $asset->id,
                'employee_id' => $employee->id,
                'assigned_by_user_id' => $actor?->id,
                'assigned_at' => now(),
                'condition_out' => $conditionOut,
                'status' => AssetAssignmentStatus::Active,
            ]);

            $asset->update([
                'status' => AssetStatus::Assigned,
                'assigned_to_employee_id' => $employee->id,
                'assigned_at' => now(),
                'returned_at' => null,
            ]);

            $this->audit->log($assignment->refresh(), 'asset.assigned', null, $this->snapshot($assignment->refresh()), $actor);
            $this->notifications->assetAssigned($assignment->refresh(), $actor);

            return $assignment->refresh();
        });
    }

    /**
     * The employee's receipt signature. Identity, not access: the policy
     * keeps everyone else out, and this refuses anyone but the holder even
     * so — a receipt signed by HR is not a receipt.
     *
     * @throws ValidationException outside active, or from the wrong hands
     */
    public function acknowledge(AssetAssignment $assignment, User $actor): AssetAssignment
    {
        $this->requireActive($assignment);

        $holderId = $assignment->employee?->user_id;

        if ($holderId === null || (int) $holderId !== (int) $actor->id) {
            throw ValidationException::withMessages(['form' => 'Only the person holding the asset acknowledges it.']);
        }

        return DB::transaction(function () use ($assignment, $actor): AssetAssignment {
            $assignment->update(['acknowledged_at' => now()]);

            $this->audit->log($assignment->refresh(), 'asset.acknowledged', null, $this->snapshot($assignment->refresh()), $actor);
            $this->notifications->assetAcknowledged($assignment->refresh(), $actor);

            return $assignment->refresh();
        });
    }

    /**
     * Take an asset back: the row closes with the condition it came back
     * in, the register returns to available carrying that condition, and
     * the assigner hears it closed. A damaged return flags the row —
     * triage (repair or write-off) is the register's next move, not this
     * method's.
     *
     * @throws ValidationException outside active
     */
    public function returnAsset(AssetAssignment $assignment, string $conditionIn, ?string $note, ?User $actor = null): AssetAssignment
    {
        $this->requireActive($assignment);

        return DB::transaction(function () use ($assignment, $conditionIn, $note, $actor): AssetAssignment {
            $assignment->update([
                'status' => $conditionIn === 'damaged' ? AssetAssignmentStatus::Damaged : AssetAssignmentStatus::Returned,
                'returned_at' => now(),
                'condition_in' => $conditionIn,
                'return_note' => $note,
            ]);

            $assignment->asset->update([
                'status' => AssetStatus::Available,
                'assigned_to_employee_id' => null,
                'returned_at' => now(),
                'condition' => $conditionIn,
            ]);

            $this->audit->log($assignment->refresh(), 'asset.returned', null, $this->snapshot($assignment->refresh()), $actor);
            $this->notifications->assetReturned($assignment->refresh(), $actor);

            return $assignment->refresh();
        });
    }

    /**
     * Everything one person currently holds, newest first — the exit
     * clearance and the self-service page read this, never the register's
     * single pointer alone.
     *
     * @return Collection<int, AssetAssignment>
     */
    public function byEmployee(Employee $employee): Collection
    {
        return AssetAssignment::query()->where('employee_id', $employee->id)
            ->where('status', AssetAssignmentStatus::Active)
            ->with(['asset:id,asset_code,name,status', 'employee:id,name'])
            ->orderByDesc('assigned_at')
            ->get();
    }

    /**
     * Active handovers still unacknowledged past the threshold: the
     * signature nobody gave. Ordered oldest first, so the weekly nudge
     * names the longest silence first.
     *
     * @return Collection<int, AssetAssignment>
     */
    public function overdueReturns(int $days = 14): Collection
    {
        return AssetAssignment::query()->where('status', AssetAssignmentStatus::Active)
            ->whereNull('acknowledged_at')
            ->where('assigned_at', '<', Carbon::now()->subDays($days)->toDateTimeString())
            ->with(['asset:id,asset_code,name', 'employee:id,name,user_id'])
            ->orderBy('assigned_at')
            ->get();
    }

    /**
     * @throws ValidationException outside active
     */
    private function requireActive(AssetAssignment $assignment): void
    {
        if ($assignment->status !== AssetAssignmentStatus::Active) {
            throw ValidationException::withMessages(['form' => 'That handover is already closed.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AssetAssignment $assignment): array
    {
        return [
            'asset_id' => $assignment->asset_id,
            'employee_id' => $assignment->employee_id,
            'status' => $assignment->status->value,
        ];
    }
}
