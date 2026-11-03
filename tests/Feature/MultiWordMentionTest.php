<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\RecipientResolver;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** The comment editor's autocomplete inserts the full display name; mentions must resolve it. */
class MultiWordMentionTest extends TestCase
{
    use IsolatesDatabase;

    private function ownerId(): int
    {
        $this->connectTenant('acme');

        return (int) User::where('email', 'owner@acme.test')->value('id');
    }

    /** @return list<string> emails */
    private function mentions(string $text): array
    {
        $this->connectTenant('acme');

        return app(RecipientResolver::class)->mentionUsers($text)->pluck('email')->sort()->values()->all();
    }

    public function test_an_id_token_is_the_identity_whatever_the_visible_name_says(): void
    {
        $this->connectTenant('acme');
        $owner = User::where('email', 'owner@acme.test')->firstOrFail();
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();

        // The name part is only a label: a stale name after a rename, or a wrong one, still reaches the right person.
        $this->assertSame(['owner@acme.test'], $this->mentions("@[Old Name Before Rename](user:{$owner->id}) hello"));
        $this->assertSame(['editor@flowsync.test', 'owner@acme.test'], $this->mentions("@[A](user:{$owner->id}) and @[B](user:{$editor->id}) and @[A again](user:{$owner->id})"));
        $this->assertSame([], $this->mentions('@[Ghost](user:999999) is nobody'));
    }

    public function test_two_people_with_the_same_name_are_told_apart_by_id(): void
    {
        $this->connectTenant('acme');
        $twin = User::create(['name' => 'Acme Corp Owner', 'email' => 'twin@acme.test', 'password' => 'password']);

        $this->assertSame(['twin@acme.test'], $this->mentions("@[Acme Corp Owner](user:{$twin->id}) can you check?"), 'the id picks one of the two');
    }

    public function test_the_snippet_shows_the_name_not_the_token(): void
    {
        $this->assertSame('Hi @Acme Corp Owner, ok?', RecipientResolver::plainText('Hi @[Acme Corp Owner](user:1), ok?'));
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

        $this->postJson("/api/projects/$pid/tasks/$tid/comments", ['comment' => '@[Acme Corp Owner]('.'user:'.$this->ownerId().') can you check?'])->assertCreated();

        $this->connectTenant('acme');
        $owner = User::where('email', 'owner@acme.test')->firstOrFail();
        $notification = UserNotification::where('user_id', $owner->id)->where('type', 'task.commented')->firstOrFail();
        $this->assertStringContainsString('@Acme Corp Owner', $notification->data['snippet']);
        $this->assertStringNotContainsString('user:', $notification->data['snippet']);
    }
}
