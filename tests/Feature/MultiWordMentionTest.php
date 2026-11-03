<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Notifications\RecipientResolver;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** The comment editor's autocomplete inserts the full display name; mentions must resolve it. */
class MultiWordMentionTest extends TestCase
{
    use IsolatesDatabase;

    /** @return list<string> emails */
    private function mentions(string $text): array
    {
        $this->connectTenant('acme');

        return app(RecipientResolver::class)->mentionUsers($text)->pluck('email')->sort()->values()->all();
    }

    public function test_a_full_display_name_from_the_autocomplete_resolves(): void
    {
        $this->assertSame(['owner@acme.test'], $this->mentions('@Acme Corp Owner  have you given me the access of that API tool?'));
        $this->assertSame(['editor@flowsync.test'], $this->mentions("@Editor User  I have done and verified this work.\n1. first"));
    }

    public function test_prose_after_the_name_is_ignored_and_case_does_not_matter(): void
    {
        $this->assertSame(['admin@flowsync.test'], $this->mentions('please look @admin user can you review this today'));
        $this->assertSame(['admin@flowsync.test'], $this->mentions('@ADMIN USER thanks'));
    }

    public function test_the_existing_forms_still_work(): void
    {
        $this->assertSame(['editor@flowsync.test'], $this->mentions('@editor please look'));
        $this->assertSame(['owner@acme.test'], $this->mentions('ping @owner@acme.test now'));
        $this->assertSame([], $this->mentions('@owner@globex.test is another tenant'), 'a foreign mailbox resolves to nobody');
        $this->assertSame([], $this->mentions('mail me at nobody@example.com'));
    }

    public function test_several_mentions_in_one_comment_all_resolve_once(): void
    {
        $this->assertSame(['admin@flowsync.test', 'editor@flowsync.test', 'owner@acme.test'],
            $this->mentions('@Acme Corp Owner and @Editor User, also @admin and again @Editor User'));
    }

    public function test_a_name_that_is_not_a_user_mentions_nobody(): void
    {
        $this->assertSame([], $this->mentions('@Nobody Here at all'));
    }

    public function test_a_commented_task_notifies_the_person_picked_from_the_list(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();
        $ws = $this->postJson('/api/workspaces', ['name' => 'Mention WS'])->assertCreated()->json('workspace.id');
        $pid = $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Mention', 'key' => 'MEN'])->assertCreated()->json('project.id');
        $tid = $this->postJson("/api/projects/$pid/tasks", ['title' => 'Mention me'])->assertCreated()->json('task.id');

        $this->postJson("/api/projects/$pid/tasks/$tid/comments", ['comment' => '@Acme Corp Owner  can you check?'])->assertCreated();

        $this->connectTenant('acme');
        $owner = User::where('email', 'owner@acme.test')->firstOrFail();
        $this->assertTrue(\App\Models\UserNotification::where('user_id', $owner->id)->where('type', 'task.commented')->exists());
    }
}
