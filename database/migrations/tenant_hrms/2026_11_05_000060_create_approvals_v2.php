<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approvals v2 (P2.4) — step modes, SLA, delegation, resubmission, templates.
 *
 * Purely additive over the shared engine tables:
 *
 *  - `approval_steps.stage|mode|sla_hours|acted_for_user_id` — steps of one
 *    stage are decided together (`sequential` = a stage of one step,
 *    `parallel_any` = first approval settles the stage, `parallel_all` = every
 *    step must approve). In-flight rows are backfilled `stage = step_order`,
 *    `mode = sequential`, so an old chain keeps its exact shape.
 *  - `approvals.domain|reminded_at|escalated_at|resubmission_of_id` — the
 *    template domain (delegation scope), the once-only reminder/escalation
 *    stamps (`due_at` already existed, unused) and the rejected approval a
 *    new one resubmits.
 *  - `approval_templates` — one editable chain per domain (seeded from the
 *    hard-coded chains by `ApprovalTemplateSeeder`; absent row = config default).
 *  - `approval_delegations` — "from" hands their approvals to "to" in a window.
 *
 * Repair-safe (`hasTable`/`hasColumn` guards, `whereNull` backfill). Columns are
 * plain (no FK) so SQLite does not rebuild the table and drop its CHECKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->extendSteps();
        $this->extendApprovals();
        $this->createTemplates();
        $this->createDelegations();
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_templates');

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_at']);
        });
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn(['domain', 'reminded_at', 'escalated_at', 'resubmission_of_id']);
        });
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropIndex(['approval_id', 'stage']);
        });
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropColumn(['stage', 'mode', 'sla_hours', 'acted_for_user_id']);
        });
    }

    private function extendSteps(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('approval_steps', 'stage')) {
                $table->unsignedInteger('stage')->nullable();
            }
            if (! Schema::hasColumn('approval_steps', 'mode')) {
                $table->string('mode', 20)->default('sequential');
            }
            if (! Schema::hasColumn('approval_steps', 'sla_hours')) {
                $table->unsignedInteger('sla_hours')->nullable();
            }
            if (! Schema::hasColumn('approval_steps', 'acted_for_user_id')) {
                $table->unsignedBigInteger('acted_for_user_id')->nullable();
            }
        });

        // Old rows: one step per stage, in order.
        DB::table('approval_steps')->whereNull('stage')->update(['stage' => DB::raw('step_order')]);

        Schema::table('approval_steps', function (Blueprint $table) {
            $names = collect(Schema::getIndexes('approval_steps'))->pluck('name')->all();

            if (! in_array('approval_steps_approval_id_stage_index', $names, true)) {
                $table->index(['approval_id', 'stage']);
            }
        });
    }

    private function extendApprovals(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('approvals', 'domain')) {
                $table->string('domain', 40)->nullable();
            }
            if (! Schema::hasColumn('approvals', 'reminded_at')) {
                $table->timestamp('reminded_at')->nullable();
            }
            if (! Schema::hasColumn('approvals', 'escalated_at')) {
                $table->timestamp('escalated_at')->nullable();
            }
            if (! Schema::hasColumn('approvals', 'resubmission_of_id')) {
                $table->unsignedBigInteger('resubmission_of_id')->nullable();
            }
        });

        Schema::table('approvals', function (Blueprint $table) {
            $names = collect(Schema::getIndexes('approvals'))->pluck('name')->all();

            if (! in_array('approvals_status_due_at_index', $names, true)) {
                $table->index(['status', 'due_at']);
            }
        });
    }

    private function createTemplates(): void
    {
        if (Schema::hasTable('approval_templates')) {
            return;
        }

        Schema::create('approval_templates', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 40)->unique();
            $table->string('name');
            $table->json('steps');
            $table->unsignedInteger('sla_hours')->nullable();
            $table->unsignedInteger('reminder_before_hours')->nullable();
            $table->string('escalation_role_slug', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    private function createDelegations(): void
    {
        if (Schema::hasTable('approval_delegations')) {
            return;
        }

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->json('domains')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['to_user_id', 'starts_at', 'ends_at']);
            $table->index('from_user_id');
        });
    }
};
