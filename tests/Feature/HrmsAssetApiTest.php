<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetCategory;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\Asset\AssetService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P14.3a — the register and its handovers over HTTP.
 *
 * Reads ride view, movements need manage, receipts belong to the holder
 * alone; a damaged return parks the asset in maintenance rather than back
 * on the shelf; and the invoice streams through the document store's
 * signed download from a fresh session.
 */
class HrmsAssetApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_viewer_reads_but_moves_nothing(): void
    {
        $asset = $this->asset();
        $holder = $this->makeEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.assets.view']));

        $this->getJson('/api/hrms/assets/categories')->assertOk();
        $this->getJson('/api/hrms/assets')->assertOk();
        $this->getJson("/api/hrms/assets/{$asset->id}")->assertOk();

        $this->postJson('/api/hrms/assets/categories', ['name' => 'Nope'])->assertForbidden();
        $this->postJson('/api/hrms/assets', ['name' => 'Nope', 'category_id' => $asset->category_id])->assertForbidden();
        $this->postJson("/api/hrms/assets/{$asset->id}/assign", ['employee_id' => $holder->id])->assertForbidden();
    }

    public function test_a_manager_runs_the_register_end_to_end(): void
    {
        $holder = $this->makeEmployee(withUser: true);
        $this->actAs($manager = $this->userWith(['hrms.view', 'hrms.assets.view', 'hrms.assets.manage']));

        $category = $this->postJson('/api/hrms/assets/categories', ['name' => 'Docking stations'])
            ->assertCreated()->json('category');

        $asset = $this->postJson('/api/hrms/assets', [
            'name' => 'Dock.',
            'category_id' => $category['id'],
            'serial_number' => 'SN-DOCK-1',
        ])->assertCreated()->json('asset');

        $this->assertMatchesRegularExpression('/^AST-\d{6}$/', $asset['asset_code']);

        $assigned = $this->postJson("/api/hrms/assets/{$asset['id']}/assign", [
            'employee_id' => $holder->id,
            'condition_out' => 'good',
        ])->assertOk()->json();

        $this->assertSame('assigned', $assigned['asset']['status']);
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $holder->user_id)
            ->where('type', 'hrms.asset.assigned')
            ->exists());

        // Twice is refused, and a stranger cannot receipt.
        $this->postJson("/api/hrms/assets/{$asset['id']}/assign", [
            'employee_id' => $holder->id,
        ])->assertStatus(422);

        $assignmentId = $assigned['assignment']['id'];
        $stranger = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view'], $stranger));
        $this->postJson("/api/hrms/assets/assignments/{$assignmentId}/acknowledge", [])->assertForbidden();

        // The holder receipts; the return closes with its condition.
        $this->actAs($this->userWith(['hrms.view'], $holder));
        $this->postJson("/api/hrms/assets/assignments/{$assignmentId}/acknowledge", [])->assertOk();

        $this->actAs($manager);
        $returned = $this->postJson("/api/hrms/assets/{$asset['id']}/return", [
            'condition_in' => 'fair',
            'return_note' => 'Scuffed.',
        ])->assertOk()->json();

        $this->assertSame('available', $returned['asset']['status']);
        $this->assertSame('fair', $returned['asset']['condition']);

        // A damaged return parks in maintenance for triage, not on the shelf.
        // The clock travels a second first: handovers are unique per
        // (asset, employee, moment) at second precision, and two test-speed
        // assigns would otherwise share a moment no human ever shares.
        Carbon::setTestNow(now()->addSecond());
        $this->postJson("/api/hrms/assets/{$asset['id']}/assign", [
            'employee_id' => $holder->id,
        ])->assertOk();
        Carbon::setTestNow();
        $this->postJson("/api/hrms/assets/{$asset['id']}/return", [
            'condition_in' => 'damaged',
        ])->assertOk()->assertJsonPath('asset.status', 'maintenance');
    }

    public function test_my_assets_are_self_scoped(): void
    {
        $holder = $this->makeEmployee(withUser: true);
        $other = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view', 'hrms.assets.view', 'hrms.assets.manage']));

        $asset = $this->postJson('/api/hrms/assets', [
            'name' => 'Monitor.',
            'category_id' => $this->category()->id,
        ])->assertCreated()->json('asset');

        $this->postJson("/api/hrms/assets/{$asset['id']}/assign", [
            'employee_id' => $holder->id,
        ])->assertOk();

        $this->actAs($this->userWith(['hrms.view'], $holder));
        $mine = $this->getJson('/api/hrms/my/assets')->assertOk()->json();
        $this->assertSame($holder->id, $mine['employee_id']);
        $this->assertCount(1, $mine['assignments']);

        $this->actAs($this->userWith(['hrms.view'], $other));
        $theirs = $this->getJson('/api/hrms/my/assets')->assertOk()->json();
        $this->assertSame($other->id, $theirs['employee_id']);
        $this->assertCount(0, $theirs['assignments']);
    }

    public function test_a_holder_returns_their_own_but_not_anothers(): void
    {
        $holder = $this->makeEmployee(withUser: true);
        $other = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view', 'hrms.assets.view', 'hrms.assets.manage']));

        $mine = $this->postJson('/api/hrms/assets', [
            'name' => 'Mine.',
            'category_id' => $this->category()->id,
        ])->assertCreated()->json('asset');
        $theirs = $this->postJson('/api/hrms/assets', [
            'name' => 'Theirs.',
            'category_id' => $this->category()->id,
        ])->assertCreated()->json('asset');

        $this->postJson("/api/hrms/assets/{$mine['id']}/assign", ['employee_id' => $holder->id])->assertOk();
        $this->postJson("/api/hrms/assets/{$theirs['id']}/assign", ['employee_id' => $other->id])->assertOk();

        // A stranger's handover is not theirs to close.
        $this->actAs($this->userWith(['hrms.view'], $holder));
        $this->postJson("/api/hrms/assets/{$theirs['id']}/return", ['condition_in' => 'good'])->assertForbidden();

        // Their own closes — damaged parks in maintenance for triage.
        $this->postJson("/api/hrms/assets/{$mine['id']}/return", [
            'condition_in' => 'damaged',
            'return_note' => 'Cracked shell.',
        ])->assertOk()->assertJsonPath('asset.status', 'maintenance');
    }

    public function test_a_used_category_is_not_deleted(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.assets.view', 'hrms.assets.manage']));
        $category = $this->category();

        $this->postJson('/api/hrms/assets', [
            'name' => 'Labelled.',
            'category_id' => $category->id,
        ])->assertCreated();

        $this->deleteJson("/api/hrms/assets/categories/{$category->id}")->assertStatus(422);
    }

    public function test_an_invoice_streams_through_a_signed_link(): void
    {
        $holder = $this->makeEmployee(withUser: true);
        $this->connectTenant('acme');

        $document = EmployeeDocument::create([
            'employee_id' => $holder->id,
            'document_type_id' => DocumentType::query()->firstOrFail()->id,
            'title' => 'Invoice.',
            'file_disk' => 'local',
            'file_path' => 'hrms/invoice.txt',
            'original_name' => 'invoice.txt',
            'mime' => 'text/plain',
            'size' => 7,
        ]);
        Storage::disk('local')->put('hrms/invoice.txt', 'invoice');

        $this->actAs($this->userWith(['hrms.view', 'hrms.assets.view', 'hrms.assets.manage']));

        $asset = $this->postJson('/api/hrms/assets', [
            'name' => 'Invoiced.',
            'category_id' => $this->category()->id,
            'invoice_document_id' => $document->id,
        ])->assertCreated()->json('asset');

        $url = URL::temporarySignedRoute('hrms.assets.document', now()->addHour(), [
            'asset' => $asset['id'],
            'tenant' => $this->acme()->id,
            // The invoice streams through the document download, which names
            // its reader: the document's owner satisfies the document view
            // rule (self), and nobody anonymous downloads.
            'actor' => $holder->user_id,
        ]);

        // Capture nothing else after this point: the session is flushed and
        // the default connection returns to the central DB below.
        $this->flushSession();
        \DB::setDefaultConnection(config('tenancy.system.connection'));

        $this->get($url)->assertOk();

        $anonymous = URL::temporarySignedRoute('hrms.assets.document', now()->addHour(), [
            'asset' => $asset['id'],
            'tenant' => $this->acme()->id,
        ]);
        $this->get($anonymous)->assertForbidden();
    }

    // ------------------------------------------------------------ helpers

    private function category(): AssetCategory
    {
        $this->connectTenant('acme');

        return AssetCategory::query()->firstOrCreate(
            ['slug' => 'laptops'],
            ['name' => 'Laptops'],
        );
    }

    private function asset(): Asset
    {
        $this->connectTenant('acme');

        return app(AssetService::class)->create([
            'name' => 'Spare.',
            'category_id' => $this->category()->id,
        ]);
    }

    private function makeEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $userId = $withUser ? User::create([
            'name' => "Asset Api Owner {$sequence}",
            'email' => "asset.api.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ])->id : null;

        return Employee::create([
            'employee_code' => 'EMP-ASA-'.$sequence,
            'name' => "Asset Api Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $userId,
        ]);
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs, ?Employee $employee = null): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        if ($employee !== null && $employee->user_id !== null) {
            $user = User::findOrFail($employee->user_id);
        } else {
            $user = User::create([
                'name' => "Asset Api User {$sequence}",
                'email' => "asset.api.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Asset Api Role {$sequence}",
            'slug' => "asset-api-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
