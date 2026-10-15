<?php

namespace App\Services\Hrms\Asset;

use App\Enums\Hrms\AssetStatus;
use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asset/HRMS — one asset taking over from another.
 *
 * The old asset keeps its row and points at its successor
 * (`replaced_by_asset_id` + reason + time). If it was out with someone the
 * handover is closed the way the reason demands — `lost` closes it as lost,
 * anything else takes it back damaged — and the old asset ends *retired* (or
 * `lost`), never available again. The successor must be an available asset that
 * is not itself a replacement target; optionally it is handed straight to the
 * previous holder, so the person is never left without equipment between two
 * clicks.
 */
class AssetReplacement
{
    public const REASONS = ['lost', 'damaged', 'faulty', 'obsolete'];

    public function __construct(
        private readonly AssetService $assets,
        private readonly AssetAssignmentService $handovers,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /** @throws ValidationException when the pair cannot be swapped */
    public function replace(Asset $old, Asset $new, string $reason, bool $assignToHolder, ?User $actor = null): Asset
    {
        $this->assertSwappable($old, $new, $reason);

        return DB::transaction(function () use ($old, $new, $reason, $assignToHolder, $actor): Asset {
            $holder = $old->assigned_to_employee_id !== null ? Employee::find($old->assigned_to_employee_id) : null;

            if ($reason === 'lost') {
                if ($old->status !== AssetStatus::Lost) {
                    $this->assets->markLost($old, $actor);
                }
            } else {
                $active = $old->assignments()->where('status', 'active')->first();
                if ($active !== null) {
                    $this->handovers->returnAsset($active, 'damaged', "Replaced ({$reason}).", $actor);
                }
                $old->refresh()->update(['status' => AssetStatus::Retired]);
            }

            $old->refresh()->update([
                'replaced_by_asset_id' => $new->id,
                'replacement_reason' => $reason,
                'replaced_at' => now(),
            ]);

            if ($assignToHolder && $holder !== null) {
                $this->handovers->assign($new->refresh(), $holder, $new->condition->value, $actor);
            }

            $this->audit->log($old->refresh(), 'asset.replaced', null, [
                'replaced_by_asset_id' => $new->id,
                'reason' => $reason,
                'reassigned' => $assignToHolder && $holder !== null,
            ], $actor);

            return $old->refresh();
        });
    }

    private function assertSwappable(Asset $old, Asset $new, string $reason): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw ValidationException::withMessages(['reason' => 'Pick why the asset is being replaced.']);
        }

        if ($old->id === $new->id) {
            throw ValidationException::withMessages(['replacement_asset_id' => 'An asset cannot replace itself.']);
        }

        if ($old->replaced_by_asset_id !== null) {
            throw ValidationException::withMessages(['form' => 'That asset has already been replaced.']);
        }

        if ($new->status !== AssetStatus::Available) {
            throw ValidationException::withMessages(['replacement_asset_id' => "The replacement is {$new->status->value} — pick an available asset."]);
        }

        if (Asset::query()->where('replaced_by_asset_id', $new->id)->exists()) {
            throw ValidationException::withMessages(['replacement_asset_id' => 'That asset is already standing in for another one.']);
        }
    }
}
