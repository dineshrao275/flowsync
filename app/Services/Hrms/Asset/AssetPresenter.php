<?php

declare(strict_types=1);

namespace App\Services\Hrms\Asset;

use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\Asset\AssetMaintenance;

/**
 * Asset/HRMS — presentation transformer for assets, handovers, and maintenance.
 */
class AssetPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function asset(Asset $asset): array
    {
        $asset->loadMissing(['category:id,name,slug', 'assignee:id,employee_code,name']);

        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'category_id' => $asset->category_id,
            'category' => $asset->category ? ['id' => $asset->category->id, 'name' => $asset->category->name, 'slug' => $asset->category->slug] : null,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'purchase_value' => $asset->purchase_value === null ? null : (string) $asset->purchase_value,
            'vendor' => $asset->vendor,
            'invoice_document_id' => $asset->invoice_document_id,
            'warranty_ends_at' => $asset->warranty_ends_at?->toDateString(),
            'condition' => $asset->condition->value,
            'status' => $asset->status->value,
            'assigned_to_employee_id' => $asset->assigned_to_employee_id,
            'assignee' => $asset->assignee ? [
                'id' => $asset->assignee->id,
                'employee_code' => $asset->assignee->employee_code,
                'name' => $asset->assignee->displayName(),
            ] : null,
            'assigned_at' => $asset->assigned_at?->toIso8601String(),
            'location_id' => $asset->location_id,
            'notes' => $asset->notes,
            'replaced_by_asset_id' => $asset->replaced_by_asset_id,
            'replacement_reason' => $asset->replacement_reason,
            'replaced_at' => $asset->replaced_at?->toIso8601String(),
            'assignments' => $asset->relationLoaded('assignments')
                ? $asset->assignments->map(fn (AssetAssignment $row): array => $this->assignment($row))->all()
                : null,
            'maintenance' => $asset->relationLoaded('maintenanceRecords')
                ? $asset->maintenanceRecords->map(fn (AssetMaintenance $row): array => $this->maintenance($row))->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assignment(AssetAssignment $assignment): array
    {
        $assignment->loadMissing(['asset:id,asset_code,name', 'employee:id,employee_code,name']);

        return [
            'id' => $assignment->id,
            'asset_id' => $assignment->asset_id,
            'asset' => $assignment->asset ? [
                'id' => $assignment->asset->id,
                'asset_code' => $assignment->asset->asset_code,
                'name' => $assignment->asset->name,
            ] : null,
            'employee_id' => $assignment->employee_id,
            'employee' => $assignment->employee ? [
                'id' => $assignment->employee->id,
                'employee_code' => $assignment->employee->employee_code,
                'name' => $assignment->employee->displayName(),
            ] : null,
            'condition_out' => $assignment->condition_out->value,
            'acknowledged_at' => $assignment->acknowledged_at?->toIso8601String(),
            'returned_at' => $assignment->returned_at?->toIso8601String(),
            'condition_in' => $assignment->condition_in?->value,
            'return_note' => $assignment->return_note,
            'status' => $assignment->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function maintenance(AssetMaintenance $record): array
    {
        return [
            'id' => $record->id,
            'asset_id' => $record->asset_id,
            'type' => $record->type->value,
            'description' => $record->description,
            'performed_by' => $record->performed_by,
            'cost' => $record->cost === null ? null : (string) $record->cost,
            'performed_at' => $record->performed_at->toDateString(),
            'next_due_at' => $record->next_due_at?->toDateString(),
            'notes' => $record->notes,
        ];
    }
}
