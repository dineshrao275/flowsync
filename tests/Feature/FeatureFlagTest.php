<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Services\FeatureFlags;
use Illuminate\Support\Facades\Route;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P8.7 - runtime flags: resolution order, SA console, route gate, authorization. */
class FeatureFlagTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    private function flags(): FeatureFlags
    {
        $flags = app(FeatureFlags::class);
        $flags->forget();

        return $flags;
    }

    public function test_unknown_and_disabled_flags_are_off_and_override_beats_global(): void
    {
        $flag = FeatureFlag::create(['key' => 'new_engine', 'enabled' => false, 'rollout_percent' => 100]);

        $this->assertFalse($this->flags()->enabled('missing', $this->acme()->id));
        $this->assertFalse($this->flags()->enabled('new_engine', $this->acme()->id));

        $flag->overrides()->create(['tenant_id' => $this->acme()->id, 'enabled' => true]);
        $this->assertTrue($this->flags()->enabled('new_engine', $this->acme()->id));
        $this->assertFalse($this->flags()->enabled('new_engine', $this->globex()->id));

        $flag->update(['enabled' => true]);
        $flag->overrides()->create(['tenant_id' => $this->globex()->id, 'enabled' => false]);
        $this->assertFalse($this->flags()->enabled('new_engine', $this->globex()->id));
    }

    public function test_rollout_percentage_is_stable_per_tenant(): void
    {
        FeatureFlag::create(['key' => 'gradual', 'enabled' => true, 'rollout_percent' => 0]);
        $this->assertFalse($this->flags()->enabled('gradual', $this->acme()->id));

        FeatureFlag::where('key', 'gradual')->update(['rollout_percent' => 100]);
        $this->assertTrue($this->flags()->enabled('gradual', $this->acme()->id));

        $bucket = FeatureFlags::bucket('gradual', $this->acme()->id);
        FeatureFlag::where('key', 'gradual')->update(['rollout_percent' => $bucket]);
        $this->assertFalse($this->flags()->enabled('gradual', $this->acme()->id));
        FeatureFlag::where('key', 'gradual')->update(['rollout_percent' => $bucket + 1]);
        $this->assertTrue($this->flags()->enabled('gradual', $this->acme()->id));
    }

    public function test_super_admin_manages_flags_and_overrides_with_audit(): void
    {
        $this->loginSuperAdmin();

        $id = $this->postJson('/api/system/feature-flags', ['key' => 'beta.board', 'enabled' => true, 'rollout_percent' => 50])
            ->assertCreated()->json('flag.id');
        $this->postJson('/api/system/feature-flags', ['key' => 'Bad Key'])->assertStatus(422);
        $this->postJson('/api/system/feature-flags', ['key' => 'beta.board'])->assertStatus(422);

        $this->putJson("/api/system/feature-flags/{$id}", ['rollout_percent' => 150])->assertStatus(422);
        $this->putJson("/api/system/feature-flags/{$id}", ['rollout_percent' => 80])->assertOk();

        $this->putJson("/api/system/feature-flags/{$id}/overrides/{$this->acme()->id}", ['enabled' => true])
            ->assertOk()->assertJsonPath('flag.overrides.0.enabled', true);
        $this->getJson('/api/system/feature-flags')->assertOk()->assertJsonPath('flags.0.key', 'beta.board');
        $this->deleteJson("/api/system/feature-flags/{$id}/overrides/{$this->acme()->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'feature_flag.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'feature_flag.override_set']);
        $this->deleteJson("/api/system/feature-flags/{$id}")->assertOk();
        $this->assertNull(FeatureFlag::where('key', 'beta.board')->first());
    }

    public function test_tenant_users_and_guests_cannot_reach_the_console(): void
    {
        $this->getJson('/api/system/feature-flags')->assertStatus(401);

        $this->loginAs('admin@flowsync.test');
        $this->getJson('/api/system/feature-flags')->assertForbidden();
        $this->postJson('/api/system/feature-flags', ['key' => 'x_flag'])->assertForbidden();
    }

    public function test_ensure_flag_middleware_gates_a_route_per_tenant(): void
    {
        Route::middleware(['web', 'switch_tenant', 'auth', 'tenant', 'ensure_flag:gated_probe'])
            ->get('/api/_probe/flag', fn () => response()->json(['ok' => true]));

        $flag = FeatureFlag::create(['key' => 'gated_probe', 'enabled' => false]);
        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/_probe/flag')->assertForbidden()->assertHeader('X-Feature-Flag', 'gated_probe');

        $flag->overrides()->create(['tenant_id' => $this->acme()->id, 'enabled' => true]);
        $this->flags();
        $this->getJson('/api/_probe/flag')->assertOk();
    }

    public function test_me_carries_the_resolved_flags_for_the_tenant(): void
    {
        FeatureFlag::create(['key' => 'on_flag', 'enabled' => true]);
        FeatureFlag::create(['key' => 'off_flag', 'enabled' => false]);
        $this->flags();
        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('user.flags.on_flag', true)
            ->assertJsonPath('user.flags.off_flag', false);
    }
}
