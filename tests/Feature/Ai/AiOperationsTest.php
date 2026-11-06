<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiDocument;
use App\Models\AiProposal;
use App\Models\AiUsage as UsageRow;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Dto\AiMessage;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiResponse;
use App\Services\Ai\Dto\AiToolCall;
use App\Services\Ai\Dto\AiUsage;
use App\Services\Ai\Exceptions\AiErrorKind;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\FakeAiProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsTmsFixtures;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Production-readiness behaviour: breaker, retention, metrics, ping, and what is (not) logged. */
class AiOperationsTest extends TestCase
{
    use BuildsTmsFixtures;
    use IsolatesDatabase;

    private function realProvider(array $extra = [])
    {
        config($extra + ['ai.enabled' => true, 'ai.provider' => 'groq', 'ai.providers.groq.key' => 'k', 'ai.max_retries' => 0, 'ai.breaker_threshold' => 3, 'ai.breaker_cooldown_seconds' => 30]);
        Cache::flush();

        return app(AiProviderResolver::class)->provider();
    }

    private function ask($provider): ?AiProviderException
    {
        try {
            $provider->complete(new AiRequest([AiMessage::user('hi')]));
        } catch (AiProviderException $e) {
            return $e;
        }

        return null;
    }

    public function test_the_breaker_opens_after_repeated_outages_and_stops_sending_requests(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'down']], 503)]);
        $provider = $this->realProvider();

        foreach (range(1, 3) as $_) {
            $this->assertSame(AiErrorKind::Unavailable, $this->ask($provider)->kind);
        }
        Http::assertSentCount(3);

        $blocked = $this->ask($provider);
        $this->assertSame(AiErrorKind::Unavailable, $blocked->kind);
        $this->assertStringContainsString('temporarily paused', $blocked->getMessage());
        Http::assertSentCount(3); // fast-failed: nothing went over the wire

        $this->travel(31)->seconds();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]])]);
        $this->assertNull($this->ask($provider));
    }

    public function test_only_outage_type_failures_trip_the_breaker(): void
    {
        $provider = $this->realProvider();

        foreach ([401, 400, 429] as $status) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response(['error' => ['message' => 'x']], $status)]);
            foreach (range(1, 4) as $_) {
                $this->ask($provider);
            }
        }

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'still reachable'], 'finish_reason' => 'stop']]])]);
        $this->assertNull($this->ask($provider));
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $provider = $this->realProvider();
        $down = fn () => Http::fake(['*' => Http::response(['error' => ['message' => 'down']], 503)]);
        $up = fn () => Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]])]);

        foreach (range(1, 2) as $_) {
            Http::swap(new Factory);
            $down();
            $this->ask($provider);
        }
        Http::swap(new Factory);
        $up();
        $this->assertNull($this->ask($provider));
        Http::swap(new Factory);
        $down();
        $this->ask($provider);
        $this->ask($provider);

        Http::swap(new Factory);
        $up();
        $this->assertNull($this->ask($provider), 'Two failures after a success must not open the breaker.');
    }

    public function test_chat_logs_metadata_only_never_the_question_or_the_answer(): void
    {
        $this->loginAs('admin@flowsync.test');
        config(['ai.enabled' => true]);
        $fake = (new FakeAiProvider)->script(
            new AiResponse('', [new AiToolCall('c1', 'navigation_help', [])], new AiUsage(10, 2), AiResponse::TOOL_CALLS),
            new AiResponse('The confidential answer about Project Falcon.', [], new AiUsage(20, 8)),
        );
        app(AiProviderResolver::class)->swap($fake);
        Log::spy();

        $this->postJson('/api/ai/chat', ['message' => 'Tell me the Falcon salary secrets'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            return $message === 'ai.chat'
                && $context['tools'] === ['navigation_help']
                && $context['input_tokens'] === 30
                && ! str_contains(json_encode($context), 'Falcon');
        })->once();
    }

    public function test_provider_failures_are_logged_without_provider_text(): void
    {
        $this->loginAs('admin@flowsync.test');
        config(['ai.enabled' => true]);
        app(AiProviderResolver::class)->swap((new FakeAiProvider)->script(new AiProviderException(AiErrorKind::Unavailable, 'upstream said: secret prompt echo', 'fake')));
        Log::spy();

        $this->postJson('/api/ai/chat', ['message' => 'hi'])->assertStatus(502);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => $m === 'ai.provider.failed' && ! str_contains(json_encode($c), 'secret prompt echo'))->once();
    }

    public function test_prune_removes_expired_files_old_chats_and_finished_proposals_only(): void
    {
        $this->connectTenant('acme');
        Storage::fake('local');
        Storage::disk('local')->put('ai/documents/old.txt', 'x');
        Storage::disk('local')->put('ai/documents/new.txt', 'x');
        $doc = fn (string $path, $expires) => AiDocument::create(['user_id' => 1, 'original_name' => $path, 'path' => $path, 'status' => 'ready', 'expires_at' => $expires]);
        $doc('ai/documents/old.txt', now()->subDay());
        $doc('ai/documents/new.txt', now()->addDay());

        $old = AiConversation::create(['user_id' => 1, 'title' => 'old', 'last_message_at' => now()->subDays(120)]);
        $old->messages()->create(['role' => 'user', 'content' => 'x']);
        $fresh = AiConversation::create(['user_id' => 1, 'title' => 'fresh', 'last_message_at' => now()]);

        $proposal = fn (string $status, $at) => tap(AiProposal::create(['user_id' => 1, 'kind' => 'tasks', 'payload' => ['items' => []], 'status' => $status]), fn ($p) => $p->forceFill(['updated_at' => $at])->saveQuietly());
        $proposal('confirmed', now()->subDays(120));
        $proposal('confirmed', now());
        $proposal('draft', now()->subDays(30));
        $proposal('draft', now()->subDay());

        UsageRow::create(['tenant_id' => $this->acme()->id, 'day' => now()->subDays(500)->toDateString(), 'requests' => 1]);
        UsageRow::create(['tenant_id' => $this->acme()->id, 'day' => now()->toDateString(), 'requests' => 1]);

        $this->artisan('ai:prune --all')->assertSuccessful();

        $this->connectTenant('acme');
        $this->assertFalse(Storage::disk('local')->exists('ai/documents/old.txt'));
        $this->assertTrue(Storage::disk('local')->exists('ai/documents/new.txt'));
        $this->assertSame(['fresh'], AiConversation::pluck('title')->all());
        $this->assertSame(0, $old->messages()->count());
        $this->assertSame(2, AiProposal::count());
        $this->assertSame(1, UsageRow::count());
    }

    public function test_platform_metrics_expose_ai_totals_without_any_tenant_text(): void
    {
        config(['observability.metrics_token' => 'secret-token']);
        UsageRow::create(['tenant_id' => $this->acme()->id, 'day' => now()->toDateString(), 'requests' => 7, 'input_tokens' => 700, 'output_tokens' => 300, 'failures' => 1, 'cost_estimate' => 0.5]);

        $body = $this->withHeader('Authorization', 'Bearer secret-token')->get('/api/metrics')->assertOk()->getContent();

        $this->assertStringContainsString('flowsync_ai_requests_today 7', $body);
        $this->assertStringContainsString('flowsync_ai_tokens_today{direction="input"} 700', $body);
        $this->assertStringContainsString('flowsync_ai_failures_today 1', $body);
        $this->assertStringContainsString('flowsync_ai_cost_estimate_usd_today 0.5', $body);
    }

    public function test_ping_reports_a_working_provider_and_never_prints_the_key(): void
    {
        config(['ai.enabled' => true, 'ai.provider' => 'groq', 'ai.providers.groq.key' => 'super-secret-key-123']);
        Http::fake(['*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'pong'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]])
            ->push(['choices' => [['message' => ['tool_calls' => [['id' => 'c', 'function' => ['name' => 'get_number', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]]),
        ]);

        $this->artisan('ai:ping --tools')
            ->expectsOutputToContain('Text: ok')
            ->expectsOutputToContain('Tools: ok')
            ->doesntExpectOutputToContain('super-secret-key-123')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer super-secret-key-123']);
    }

    public function test_ping_fails_clearly_on_bad_configuration_and_on_a_provider_error(): void
    {
        config(['ai.enabled' => true, 'ai.provider' => 'groq', 'ai.providers.groq.key' => '']);
        $this->artisan('ai:ping')->expectsOutputToContain('No API key for groq')->assertFailed();

        config(['ai.providers.groq.key' => 'k', 'ai.max_retries' => 0]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'invalid key']], 401)]);
        $this->artisan('ai:ping')->expectsOutputToContain('auth')->assertFailed();
    }
}
