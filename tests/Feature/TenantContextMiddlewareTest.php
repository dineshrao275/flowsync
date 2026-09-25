<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantContextMiddlewareTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'switch_tenant', 'auth', 'tenant', 'tenant_context'])
            ->get('api/_probe', fn () => response()->json(['ok' => true]));
    }

    public function test_tenant_user_can_access_tenant_scoped_route(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/_probe')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_non_impersonating_super_admin_is_blocked(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/_probe')->assertForbidden();
    }

    public function test_impersonating_super_admin_passes_middleware(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        // HTTP login restores the system connection; resolve the target on Acme's DB.
        $this->connectTenant('acme');

        $target = User::where('email', 'admin@flowsync.test')->first();
        $this->postJson('/api/impersonate', ['user_id' => $target->id])->assertOk();

        $this->getJson('/api/_probe')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/_probe')->assertUnauthorized();
    }
}
