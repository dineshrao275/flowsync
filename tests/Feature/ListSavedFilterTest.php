<?php

namespace Tests\Feature;

use Tests\IsolatesDatabase;
use Tests\TestCase;

class ListSavedFilterTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_user_saves_lists_updates_and_deletes_their_own_filter(): void
    {
        $this->loginAs('editor@flowsync.test');

        $id = $this->postJson('/api/saved-filters', ['page' => 'users', 'name' => 'Admins', 'filters' => ['role' => 'admin', 'q' => '']])
            ->assertCreated()->assertJsonPath('filter.filters.role', 'admin')->json('filter.id');

        $this->getJson('/api/saved-filters?page=users')->assertOk()->assertJsonCount(1, 'filters');
        $this->getJson('/api/saved-filters?page=other')->assertOk()->assertJsonCount(0, 'filters');

        $this->putJson("/api/saved-filters/{$id}", ['filters' => ['role' => 'editor']])->assertOk()->assertJsonPath('filter.filters.role', 'editor');
        $this->deleteJson("/api/saved-filters/{$id}")->assertOk();
        $this->getJson('/api/saved-filters?page=users')->assertJsonCount(0, 'filters');
    }

    public function test_private_filters_are_invisible_to_others_and_sharing_needs_tenant_manage(): void
    {
        $this->loginAs('editor@flowsync.test');
        $this->postJson('/api/saved-filters', ['page' => 'users', 'name' => 'Mine', 'filters' => ['q' => 'x'], 'is_shared' => true])->assertForbidden();
        $private = $this->postJson('/api/saved-filters', ['page' => 'users', 'name' => 'Mine', 'filters' => ['q' => 'x']])->json('filter.id');

        $this->loginAs('admin@flowsync.test');
        $this->getJson('/api/saved-filters?page=users')->assertJsonCount(0, 'filters');
        $this->putJson("/api/saved-filters/{$private}", ['name' => 'Hijack'])->assertNotFound();

        $shared = $this->postJson('/api/saved-filters', ['page' => 'users', 'name' => 'Team', 'filters' => ['q' => 'y'], 'is_shared' => true])->assertCreated()->json('filter.id');

        $this->loginAs('editor@flowsync.test');
        $this->getJson('/api/saved-filters?page=users')->assertJsonCount(2, 'filters');
        $this->deleteJson("/api/saved-filters/{$shared}")->assertForbidden();
    }

    public function test_nested_or_oversized_filter_values_are_refused(): void
    {
        $this->loginAs('editor@flowsync.test');

        $this->postJson('/api/saved-filters', ['page' => 'users', 'name' => 'Bad', 'filters' => ['a' => ['b' => ['c']]]])->assertStatus(422);
        $this->postJson('/api/saved-filters', ['page' => 'Bad Page!', 'name' => 'Bad', 'filters' => ['a' => 1]])->assertStatus(422);
    }

    public function test_super_admins_have_their_own_store(): void
    {
        $this->loginAs('superadmin@flowsync.test');

        $this->postJson('/api/system/saved-filters', ['page' => 'tenants', 'name' => 'Trials', 'filters' => ['status' => 'trial']])->assertCreated();
        $this->getJson('/api/system/saved-filters?page=tenants')->assertOk()->assertJsonCount(1, 'filters');
    }

    private function loginAs(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }
}
