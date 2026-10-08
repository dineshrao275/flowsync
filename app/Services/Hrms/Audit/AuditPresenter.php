<?php

namespace App\Services\Hrms\Audit;

use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Services\HrmsAuditLogger;

/**
 * Audit/HRMS — what a trail row looks like over HTTP.
 *
 * One shape for the index, the record trail and the profile tab, so the
 * three cannot drift into three dialects for the same ledger. The `data`
 * payload arrives already masked from {@see HrmsAuditLogger}
 * (values never leave the writer unmasked); this presents it verbatim and
 * adds the actor's display name so the client needs no second lookup.
 */
class AuditPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(HrmsAuditLog $row): array
    {
        return [
            'id' => $row->id,
            'action' => $row->action,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'data' => $row->data,
            'ip_address' => $row->ip_address,
            'created_at' => $row->created_at?->toISOString(),
            'actor' => $row->actor === null ? null : [
                'id' => $row->actor->id,
                'name' => $row->actor->name,
            ],
        ];
    }

    /**
     * One who-read-what row: the model, the record, the action, and the
     * field names that travelled — never values (the writer's contract).
     *
     * @return array<string, mixed>
     */
    public function presentAccess(HrmsDataAccessLog $row): array
    {
        return [
            'id' => $row->id,
            'model' => $row->model,
            'record_id' => $row->record_id,
            'action' => $row->action->value,
            'fields' => $row->fields,
            'ip_address' => $row->ip_address,
            'created_at' => $row->created_at?->toISOString(),
            'actor' => $row->actor === null ? null : [
                'id' => $row->actor->id,
                'name' => $row->actor->name,
            ],
        ];
    }
}
