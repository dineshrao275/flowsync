<?php

namespace App\Services\Hrms\Approval;

use App\Models\Hrms\Shared\ApprovalTemplate;
use Illuminate\Support\Facades\Schema;

/**
 * Approval/HRMS — one `approval_templates` row per domain, copied from
 * `config/approvals.php` so a tenant starts on exactly the chains that used
 * to be hard-coded (P2.4).
 *
 * Insert-only (the HrmsDefaultsProvisioner rule): `tenants:provision` runs on
 * every repair, and an update would silently undo a tenant's own edits.
 * Guarded on the table so a database that has not migrated approvals v2 yet
 * is a no-op rather than a failure.
 */
class ApprovalTemplateSeeder
{
    public function seed(): void
    {
        if (! Schema::hasTable('approval_templates')) {
            return;
        }

        foreach (config('approvals.domains', []) as $domain => $definition) {
            ApprovalTemplate::query()->firstOrCreate(
                ['domain' => $domain],
                ['name' => $definition['label'], 'steps' => $definition['steps'], 'is_active' => true],
            );
        }
    }
}
