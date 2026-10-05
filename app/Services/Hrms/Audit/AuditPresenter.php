<?php

namespace App\Services\Hrms\Audit;

use App\Models\Hrms\Shared\HrmsAuditLog;
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
}
