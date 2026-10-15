<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\UploadedFile;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** FB-8 — CSV user import and the separate user edit endpoints. */
class UserImportTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('users.csv', $body);
    }

    private const HEADER = "name,email,roles,password\r\n";

    public function test_the_sample_file_is_downloadable_and_is_itself_importable(): void
    {
        $this->login('admin@flowsync.test');

        $sample = $this->get('/api/users/import/sample')->assertOk();
        $this->assertStringContainsString('name,email,roles,password', $sample->getContent());

        $this->post('/api/users/import/preview', ['file' => $this->csv($sample->getContent())], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('summary.invalid', 0);
    }

    public function test_a_valid_file_creates_users_who_can_sign_in_and_returns_generated_passwords_once(): void
    {
        $this->login('admin@flowsync.test');

        $response = $this->post('/api/users/import', ['file' => $this->csv(
            self::HEADER."Ann Import,ann@import.test,viewer,\r\nBob Import,Bob@Import.test,editor|viewer,Chosen-pass-1\r\n"
        )], ['Accept' => 'application/json'])->assertCreated();

        $created = collect($response->json('created'))->keyBy('email');
        $this->assertNotNull($created['ann@import.test']['temporary_password']);
        $this->assertNull($created['bob@import.test']['temporary_password']); // supplied, never echoed
        $this->assertEqualsCanonicalizing(['editor', 'viewer'], $created['bob@import.test']['roles']);

        $this->assertTrue(TenantUserRouting::where('email', 'ann@import.test')->exists());
        $this->assertSame(1, AuditLog::where('action', 'users.imported')->count());

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'ann@import.test', 'password' => $created['ann@import.test']['temporary_password']])->assertOk();
    }

    public function test_row_errors_are_reported_per_line_and_block_the_import_unless_skipped(): void
    {
        $this->login('admin@flowsync.test');
        $file = fn () => $this->csv(self::HEADER.
            "Good One,good@import.test,viewer,\r\n".
            ",nobody@import.test,viewer,\r\n".
            "Bad Mail,not-an-email,viewer,\r\n".
            "Dup,admin@flowsync.test,viewer,\r\n".
            "Ghost Role,ghost@import.test,wizard,\r\n".
            "Twin,good@import.test,viewer,\r\n");

        $preview = $this->post('/api/users/import/preview', ['file' => $file()], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(['total' => 6, 'valid' => 1, 'invalid' => 5], $preview->json('summary'));
        $byLine = collect($preview->json('rows'))->keyBy('line');
        $this->assertStringContainsString('Name is required', $byLine[3]['errors'][0]);
        $this->assertStringContainsString('not a valid', $byLine[4]['errors'][0]);
        $this->assertStringContainsString('already exists', $byLine[5]['errors'][0]);
        $this->assertStringContainsString("Unknown role 'wizard'", $byLine[6]['errors'][0]);
        $this->assertStringContainsString('repeats line 2', $byLine[7]['errors'][0]);

        $this->post('/api/users/import', ['file' => $file()], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertFalse(User::where('email', 'good@import.test')->exists());

        $this->post('/api/users/import', ['file' => $file(), 'skip_invalid' => true], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('summary.valid', 1);
        $this->assertTrue(User::where('email', 'good@import.test')->exists());
    }

    public function test_an_importer_cannot_assign_roles_above_their_own(): void
    {
        // An editor who may manage users but does not hold every permission of the admin role.
        $manager = Role::create(['name' => 'User Manager', 'slug' => 'user-manager']);
        $manager->permissions()->sync(Permission::whereIn('slug', ['users.view', 'users.manage', 'roles.view'])->pluck('id'));
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $editor->roles()->sync([$manager->id]);

        $this->login('editor@flowsync.test');
        $preview = $this->post('/api/users/import/preview', ['file' => $this->csv(
            self::HEADER."Sneaky,sneaky@import.test,admin,\r\nOk Person,okp@import.test,user-manager,\r\n"
        )], ['Accept' => 'application/json'])->assertOk();

        $rows = collect($preview->json('rows'))->keyBy('email');
        $this->assertStringContainsString('Only an administrator', $rows['sneaky@import.test']['errors'][0]);
        $this->assertSame([], $rows['okp@import.test']['errors']);
        $this->assertNotContains('admin', array_column($preview->json('available_roles'), 'slug'));

        $this->post('/api/users/import', ['file' => $this->csv(self::HEADER."Sneaky,sneaky@import.test,admin,\r\n")], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertFalse(User::where('email', 'sneaky@import.test')->exists());
    }

    public function test_the_plan_user_limit_stops_the_import(): void
    {
        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        $plan = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        $plan->update(['limits' => [...$plan->limits, 'users' => User::count() + 1]]);
        app(SubscriptionService::class)->assign($acme, $plan);

        $this->login('admin@flowsync.test');
        $preview = $this->post('/api/users/import/preview', ['file' => $this->csv(
            self::HEADER."One,one@import.test,viewer,\r\nTwo,two@import.test,viewer,\r\n"
        )], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, $preview->json('summary.valid'));
        $this->assertStringContainsString('user limit', $preview->json('rows.1.errors.0'));
    }

    public function test_a_bad_file_is_rejected_up_front(): void
    {
        $this->login('admin@flowsync.test');

        $this->post('/api/users/import/preview', ['file' => $this->csv("foo,bar\r\n1,2\r\n")], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_import_needs_users_manage(): void
    {
        $this->login('viewer@flowsync.test');

        $this->post('/api/users/import/preview', ['file' => $this->csv(self::HEADER)], ['Accept' => 'application/json'])->assertForbidden();
        $this->get('/api/users/import/sample', ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_user_is_edited_on_their_own_endpoint_and_the_login_routing_follows(): void
    {
        $editorId = User::where('email', 'editor@flowsync.test')->value('id');
        $this->login('admin@flowsync.test');
        $editor = (object) ['id' => $editorId];

        $this->getJson("/api/users/{$editor->id}")->assertOk()->assertJsonPath('user.email', 'editor@flowsync.test');
        $this->putJson("/api/users/{$editor->id}", ['name' => 'Edie Tor', 'email' => 'Edie@Flowsync.test'])
            ->assertOk()->assertJsonPath('user.email', 'edie@flowsync.test');

        $this->assertTrue(TenantUserRouting::where('email', 'edie@flowsync.test')->exists());
        $this->assertFalse(TenantUserRouting::where('email', 'editor@flowsync.test')->exists());
        $this->assertSame(1, AuditLog::where('action', 'user.updated')->count());

        $this->putJson("/api/users/{$editor->id}", ['name' => 'X', 'email' => 'viewer@flowsync.test'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
