<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Dto\AiMessage;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiToolSpec;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Console\Command;

/**
 * One live round trip against the configured provider with a fixed synthetic prompt: no tenant data, no
 * database, no key printed. Use it after changing AI_PROVIDER / AI_MODEL / a key to prove the wire format
 * and (with --tools) tool calling work before users do.
 */
class AiPing extends Command
{
    protected $signature = 'ai:ping {--tools : also check that the model can call a tool}';

    protected $description = 'Send one synthetic request to the configured AI provider and report the result';

    public function handle(AiProviderResolver $resolver): int
    {
        try {
            $provider = $resolver->provider();
        } catch (AiConfigurationException $e) {
            foreach ($e->problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $this->line("Provider: {$provider->name()}  Model: {$provider->model()}");

        try {
            $start = hrtime(true);
            $reply = $provider->complete(new AiRequest([AiMessage::user('Reply with the single word: pong')], [], 32, 0.0));
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->info("Text: ok in {$ms} ms, {$reply->usage->total()} tokens: ".trim(mb_substr($reply->text, 0, 60)));

            if ($this->option('tools')) {
                return $this->tools($provider);
            }
        } catch (AiProviderException $e) {
            $this->error("{$e->kind->value}: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function tools(AiProvider $provider): int
    {
        if (! $provider->capabilities()->tools) {
            $this->warn('This model is configured without tool calling; skipped.');

            return self::SUCCESS;
        }

        $tool = new AiToolSpec('get_number', 'Returns the secret number. Call it to answer questions about the secret number.', [
            'type' => 'object', 'properties' => ['reason' => ['type' => 'string']], 'required' => [],
        ]);
        $reply = $provider->complete(new AiRequest([AiMessage::user('What is the secret number? Use the tool.')], [$tool], 64, 0.0));

        if (! $reply->wantsTools() || $reply->toolCalls[0]->name !== 'get_number') {
            $this->error('Tools: the model did not call the tool.');

            return self::FAILURE;
        }

        $this->info('Tools: ok, the model called get_number.');

        return self::SUCCESS;
    }
}
