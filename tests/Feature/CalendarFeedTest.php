<?php

namespace Tests\Feature;

use App\Services\Integrations\CalendarFeed;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F10.7 — a secret-URL iCalendar feed of your due tasks: revocable, hashed at rest, RFC-escaped. */
class CalendarFeedTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_feed_lists_my_due_tasks_and_dies_with_the_link(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $me = $this->getJson('/api/auth/me')->json('user.id');
        $ws = $this->postJson('/api/workspaces', ['name' => 'Cal WS'])->assertCreated()->json('workspace.id');
        $pid = $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Cal P', 'key' => 'CAL'])->assertCreated()->json('project.id');
        $this->postJson("/api/projects/$pid/tasks", ['title' => 'Ship, now; please', 'assignee_id' => $me, 'due_date' => now()->addDays(3)->toDateString()])->assertCreated();

        $url = $this->postJson('/api/calendar-feed')->assertOk()->json('url');
        $this->assertTrue($this->getJson('/api/calendar-feed')->json('active'));

        $this->flushSession();
        $res = $this->get($url)->assertOk();
        $this->assertStringContainsString('text/calendar', $res->headers->get('Content-Type'));
        $body = $res->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('Ship\, now\; please', $body);

        $this->get(preg_replace('/\w{40}$/', str_repeat('a', 40), $url))->assertNotFound();

        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->deleteJson('/api/calendar-feed')->assertOk();
        $this->flushSession();
        $this->get($url)->assertNotFound();
    }

    public function test_text_is_escaped_and_long_lines_are_folded_without_splitting_characters(): void
    {
        $this->assertSame('a\\,b\;c\\nd\\\\', CalendarFeed::esc("a,b;c\nd\\"));

        $folded = CalendarFeed::fold('SUMMARY:'.str_repeat('é', 60));
        foreach (explode("\r\n", $folded) as $part) {
            $this->assertLessThanOrEqual(75, strlen($part));
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
        }
    }
}
