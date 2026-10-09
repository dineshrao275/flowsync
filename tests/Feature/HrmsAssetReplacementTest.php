<?php

namespace Tests\Feature;

use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetCategory;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Services\Hrms\Asset\AssetAssignmentService;
use App\Services\Hrms\Asset\AssetService;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.16 — an asset replacing another: the old one is closed out (returned
 * damaged or marked lost, then retired), points at its successor, and the
 * successor can be handed to the previous holder in the same step.
 */
class HrmsAssetReplacementTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.assets']);
        $this->loginAdmin();
    }

    private function asset(string $name): Asset
    {
        $category = AssetCategory::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops']);

        return app(AssetService::class)->create(['name' => $name, 'category_id' => $category->id]);
    }

    public function test_a_damaged_asset_is_retired_and_the_holder_gets_the_replacement(): void
    {
        $holder = $this->makeEmployee('Holder');
        $old = $this->asset('Old laptop');
        $new = $this->asset('New laptop');
        app(AssetAssignmentService::class)->assign($old, $holder, 'good');

        $this->postJson("/api/hrms/assets/{$old->id}/replace", [
            'replacement_asset_id' => $new->id, 'reason' => 'damaged', 'assign_to_holder' => true,
        ])->assertOk()->assertJsonPath('asset.status', 'retired');

        $this->assertSame($new->id, $old->fresh()->replaced_by_asset_id);
        $this->assertSame('damaged', $old->fresh()->replacement_reason);
        $this->assertSame('assigned', $new->fresh()->status->value);
        $this->assertSame($holder->id, $new->fresh()->assigned_to_employee_id);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'asset.replaced')->exists());
    }

    public function test_a_lost_asset_stays_lost_and_closes_the_handover(): void
    {
        $holder = $this->makeEmployee('Loser');
        $old = $this->asset('Lost laptop');
        $new = $this->asset('Spare laptop');
        app(AssetAssignmentService::class)->assign($old, $holder, 'good');

        $this->postJson("/api/hrms/assets/{$old->id}/replace", ['replacement_asset_id' => $new->id, 'reason' => 'lost'])
            ->assertOk()->assertJsonPath('asset.status', 'lost');

        $this->assertSame('available', $new->fresh()->status->value);
        $this->assertSame(0, $old->assignments()->where('status', 'active')->count());
    }

    public function test_bad_pairs_are_refused(): void
    {
        $old = $this->asset('A');
        $busy = $this->asset('B');
        app(AssetAssignmentService::class)->assign($busy, $this->makeEmployee('Busy'), 'good');

        $this->postJson("/api/hrms/assets/{$old->id}/replace", ['replacement_asset_id' => $old->id, 'reason' => 'faulty'])
            ->assertUnprocessable()->assertJsonValidationErrors('replacement_asset_id');
        $this->postJson("/api/hrms/assets/{$old->id}/replace", ['replacement_asset_id' => $busy->id, 'reason' => 'faulty'])
            ->assertUnprocessable()->assertJsonValidationErrors('replacement_asset_id');
        $this->postJson("/api/hrms/assets/{$old->id}/replace", ['replacement_asset_id' => $busy->id, 'reason' => 'whim'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_an_asset_is_replaced_only_once_and_needs_manage(): void
    {
        $old = $this->asset('Once');
        $first = $this->asset('First');
        $second = $this->asset('Second');

        $this->postJson("/api/hrms/assets/{$old->id}/replace", ['replacement_asset_id' => $first->id, 'reason' => 'obsolete'])->assertOk();
        $this->postJson("/api/hrms/assets/{$old->id}/replace", ['replacement_asset_id' => $second->id, 'reason' => 'obsolete'])
            ->assertUnprocessable()->assertJsonValidationErrors('form');

        $this->actAs($this->userWith(['hrms.view', 'hrms.assets.view']));
        $this->postJson("/api/hrms/assets/{$second->id}/replace", ['replacement_asset_id' => $first->id, 'reason' => 'obsolete'])->assertForbidden();
    }
}
