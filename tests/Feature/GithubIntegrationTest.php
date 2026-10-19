<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Task;
use App\Models\Tenant;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F10.7 — signed GitHub webhooks leave a line on the tasks they name, and nothing else. */
class GithubIntegrationTest extends TestCase
{
    use IsolatesDatabase;

    private function sign(string $body, string $secret): array
    {
        return ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret), 'X-GitHub-Event' => 'pull_request', 'Content-Type' => 'application/json'];
    }

    public function test_a_signed_pull_request_links_its_task_and_bad_signatures_are_404(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $cfg = $this->putJson('/api/github-integration', ['enabled' => true])->assertOk();
        $secret = $cfg->json('secret');
        $this->assertStringStartsWith('ghs_', $secret);
        $this->assertNull($this->putJson('/api/github-integration', ['enabled' => true])->json('secret'));   // shown once

        $ws = $this->postJson('/api/workspaces', ['name' => 'GH WS'])->assertCreated()->json('workspace.id');
        $pid = $this->postJson("/api/workspaces/$ws/projects", ['name' => 'GH Project', 'key' => 'GHP'])->assertCreated()->json('project.id');
        $tid = $this->postJson("/api/projects/$pid/tasks", ['title' => 'Login bug'])->assertCreated()->json('task.id');
        $this->connectTenant('acme');
        $task = Task::findOrFail($tid);
        $payload = json_encode(['action' => 'opened', 'repository' => ['full_name' => 'acme/app'], 'pull_request' => [
            'title' => "Fix login ({$task->key})", 'html_url' => 'https://github.com/acme/app/pull/7', 'head' => ['ref' => 'fix/login'], 'merged' => false,
        ]]);
        $url = '/api/integrations/github/'.Tenant::where('slug', 'acme')->value('id');

        $this->call('POST', $url, [], [], [], $this->transform($this->sign($payload, 'wrong')), $payload)->assertNotFound();
        $this->call('POST', $url, [], [], [], $this->transform($this->sign($payload, $secret)), $payload)->assertStatus(202)->assertJsonPath('linked', 1);

        $this->connectTenant('acme');
        $act = Activity::where('action', 'task.vcs_event')->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame('pull_request', $act->data['kind']);
        $this->assertSame('https://github.com/acme/app/pull/7', $act->data['url']);
    }

    public function test_a_disabled_integration_and_foreign_urls_are_ignored(): void
    {
        $payload = '{}';
        $this->call('POST', '/api/integrations/github/'.Tenant::where('slug', 'acme')->value('id'), [], [], [], $this->transform($this->sign($payload, 'x')), $payload)->assertNotFound();
        $this->call('POST', '/api/integrations/github/999999', [], [], [], $this->transform($this->sign($payload, 'x')), $payload)->assertNotFound();
    }

    private function transform(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $out;
    }
}
