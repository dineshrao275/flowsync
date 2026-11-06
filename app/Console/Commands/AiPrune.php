<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiConversation;
use App\Models\AiDocument;
use App\Models\AiProposal;
use App\Models\AiUsage;
use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Retention for AI data. Uploaded requirement files go quickly (they are only needed to draft a proposal);
 * chat history and finished proposals follow the conversation window; the central usage counters, which hold
 * no text, are kept longest. The audit trail (activity rows) is never touched here.
 */
class AiPrune extends Command
{
    protected $signature = 'ai:prune {--all : every serviceable tenant}';

    protected $description = 'Delete expired AI documents, old conversations and finished proposals';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $files = $conversations = $proposals = 0;
        $tenants = Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get();
        $cutoff = now()->subDays((int) config('ai.conversation_retention_days'));

        foreach ($tenants as $tenant) {
            $dbm->using($tenant, function () use (&$files, &$conversations, &$proposals, $cutoff): void {
                if (! Schema::hasTable('ai_conversations')) {
                    return;
                }

                AiDocument::query()->where('expires_at', '<', now())->each(function (AiDocument $document) use (&$files): void {
                    Storage::disk('local')->delete($document->path);
                    $document->delete();
                    $files++;
                });
                // Drafts are left to the document window above; a decided proposal is history like a chat.
                $proposals += AiProposal::query()->where('status', '!=', AiProposal::DRAFT)->where('updated_at', '<', $cutoff)->delete();
                $proposals += AiProposal::query()->where('status', AiProposal::DRAFT)->where('updated_at', '<', now()->subDays(max(1, (int) config('ai.document_retention_days'))))->delete();
                $conversations += AiConversation::query()->where('last_message_at', '<', $cutoff)->delete();
            });
        }

        $usage = AiUsage::query()->where('day', '<', now()->subDays((int) config('ai.usage_retention_days'))->toDateString())->delete();

        $this->info("Pruned {$files} file(s), {$conversations} conversation(s), {$proposals} proposal(s) and {$usage} usage row(s) across {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
