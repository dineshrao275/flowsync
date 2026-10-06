<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();

        $this->connectTenant('acme');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->first();
    }

    public function test_defaults_are_returned_before_any_row_exists(): void
    {
        $this->login('admin@flowsync.test');

        $preferences = $this->getJson('/api/notification-preferences')
            ->assertOk()
            ->json('preferences');

        $this->assertTrue($preferences['task.assigned']);
        $this->assertTrue($preferences['task.commented']);
        $this->assertTrue($preferences['task.work_logged']);
        $this->assertNotEmpty($preferences);
    }

    public function test_an_event_can_be_toggled_off_and_the_row_persists(): void
    {
        $this->login('admin@flowsync.test');

        $preferences = $this->putJson('/api/notification-preferences', [
            'preferences' => ['task.assigned' => false],
        ])
            ->assertOk()
            ->json('preferences');

        $this->assertFalse($preferences['task.assigned']);
        $this->assertTrue($preferences['task.commented']);

        $row = NotificationPreference::firstWhere('user_id', $this->admin()->id);
        $this->assertNotNull($row);
        $this->assertFalse($row->preferences['task.assigned']);

        $this->assertFalse(NotificationPreference::wants($this->admin(), 'task.assigned'));
        $this->assertTrue(NotificationPreference::wants($this->admin(), 'task.commented'));
    }

    public function test_a_toggle_is_merged_onto_existing_preferences(): void
    {
        $this->login('admin@flowsync.test');

        $this->putJson('/api/notification-preferences', [
            'preferences' => ['task.commented' => false],
        ])->assertOk();

        $preferences = $this->putJson('/api/notification-preferences', [
            'preferences' => ['task.unblocked' => false],
        ])
            ->assertOk()
            ->json('preferences');

        $this->assertFalse($preferences['task.commented']);
        $this->assertFalse($preferences['task.unblocked']);

        $this->assertFalse(NotificationPreference::wants($this->admin(), 'task.commented'));
        $this->assertFalse(NotificationPreference::wants($this->admin(), 'task.unblocked'));
    }

    public function test_unknown_event_is_rejected(): void
    {
        $this->login('admin@flowsync.test');

        $this->putJson('/api/notification-preferences', [
            'preferences' => ['task.delivered' => false],
        ])->assertStatus(422)->assertJsonValidationErrors('preferences');
    }

    public function test_a_non_boolean_value_is_rejected(): void
    {
        $this->login('admin@flowsync.test');

        $this->putJson('/api/notification-preferences', [
            'preferences' => ['task.assigned' => 'sometimes'],
        ])->assertStatus(422)->assertJsonValidationErrors('preferences.task.assigned');
    }

    public function test_uncatalogued_events_always_deliver_and_cannot_be_toggled(): void
    {
        $this->login('admin@flowsync.test');

        $this->assertTrue(NotificationPreference::wants($this->admin(), 'hrms.leave.approved'));
    }

    public function test_another_user_gets_their_own_defaults_and_no_row(): void
    {
        $this->login('admin@flowsync.test');

        $this->putJson('/api/notification-preferences', [
            'preferences' => ['task.assigned' => false],
        ])->assertOk();

        $editor = User::where('email', 'editor@flowsync.test')->first();
        $this->assertTrue(NotificationPreference::wants($editor, 'task.assigned'));
        $this->assertFalse(NotificationPreference::where('user_id', $editor->id)->exists());
    }
}
