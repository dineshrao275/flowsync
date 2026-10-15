<?php

namespace App\Services\Hrms\Asset;

use App\Enums\Hrms\AssetStatus;
use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetMaintenance;
use App\Models\User;
use App\Services\Hrms\Org\OrgNaming;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Auditable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asset/HRMS — the register: what exists, what shape it is in, and whether
 * it is serviceable.
 *
 * Six methods, one per register verb: enter an item, edit its facts,
 * retire it, lose it, open a repair, close one. Handovers (who holds what,
 * and their receipts) live in `AssetAssignmentService` — the register
 * answers "what and how", the ledger answers "who since when", and neither
 * reimplements the other. Every mutation audits identifiers only; money
 * (purchase value, repair cost) never enters the ledger.
 */
class AssetService
{
    public function __construct(
        private readonly OrgNaming $naming,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Asset
    {
        return DB::transaction(function () use ($data, $actor): Asset {
            $asset = Asset::create([...$data, 'asset_code' => 'pending']);

            $asset->update(['asset_code' => sprintf('AST-%06d', $asset->id)]);

            $this->audit->log($asset->refresh(), 'asset.created', null, $this->snapshot($asset->refresh()), $actor);

            return $asset->refresh();
        });
    }

    /**
     * Edit an asset's facts. Assignment state is not editable here — hands
     * change through assign/return, condition through use and repair, and
     * a form that edits `status` directly would unassign people silently.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Asset $asset, array $data, ?User $actor = null): Asset
    {
        unset($data['status'], $data['assigned_to_employee_id'], $data['assigned_at'], $data['returned_at']);

        $before = $this->snapshot($asset);
        $asset->update($data);

        $this->audit->log($asset->refresh(), 'asset.updated', $before, $this->snapshot($asset->refresh()), $actor);

        return $asset->refresh();
    }

    /**
     * Retire an asset out of service. Refuses while checked out — return it
     * first, so the ledger never shows a retirement swallowing a handover.
     *
     * @throws ValidationException while assigned or in maintenance
     */
    public function retire(Asset $asset, ?User $actor = null): Asset
    {
        if ($asset->status === AssetStatus::Assigned || $asset->status === AssetStatus::Maintenance) {
            throw ValidationException::withMessages(['form' => 'That asset is out — return or repair it before retiring.']);
        }

        return DB::transaction(function () use ($asset, $actor): Asset {
            $before = $this->snapshot($asset);
            $asset->update(['status' => AssetStatus::Retired]);

            $this->audit->log($asset->refresh(), 'asset.retired', $before, $this->snapshot($asset->refresh()), $actor);

            return $asset->refresh();
        });
    }

    /**
     * Declare an asset lost: the register says so, and any open handover
     * closes as lost with it — a lost laptop with an "active" handover
     * would keep blocking its holder's exit forever.
     */
    public function markLost(Asset $asset, ?User $actor = null): Asset
    {
        return DB::transaction(function () use ($asset, $actor): Asset {
            $before = $this->snapshot($asset);

            $asset->assignments()->where('status', 'active')->update(['status' => 'lost']);
            $asset->update([
                'status' => AssetStatus::Lost,
                'assigned_to_employee_id' => null,
                'returned_at' => now(),
            ]);

            $this->audit->log($asset->refresh(), 'asset.lost', $before, $this->snapshot($asset->refresh()), $actor);

            return $asset->refresh();
        });
    }

    /**
     * Open a repair record: the asset parks in maintenance while it is
     * open. Retired and lost assets refuse — the shop repairs the living.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException on a terminal asset
     */
    public function maintenance(Asset $asset, array $data, ?User $actor = null): AssetMaintenance
    {
        if ($asset->status->isTerminal()) {
            throw ValidationException::withMessages(['form' => 'That asset is out of service — maintenance is for the living.']);
        }

        return DB::transaction(function () use ($asset, $data, $actor): AssetMaintenance {
            $record = $asset->maintenanceRecords()->create([...$data, 'created_by' => $actor?->id]);
            $asset->update(['status' => AssetStatus::Maintenance]);

            $this->audit->log($asset->refresh(), 'asset.maintenance_opened', null, $this->snapshot($asset->refresh()), $actor);

            return $record->refresh();
        });
    }

    /**
     * Close the loop: the asset returns to service. Without this, every
     * repair would strand its asset in maintenance forever — an open
     * without a close is a write-only workflow.
     */
    public function closeMaintenance(AssetMaintenance $record, ?User $actor = null): Asset
    {
        $asset = $record->asset;

        return DB::transaction(function () use ($asset, $actor): Asset {
            $asset->update(['status' => AssetStatus::Available]);

            $this->audit->log($asset->refresh(), 'asset.maintenance_closed', null, $this->snapshot($asset->refresh()), $actor);

            return $asset->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Asset $asset): array
    {
        return Auditable::snapshot($asset, ['asset_code', 'status', 'condition']);
    }
}
