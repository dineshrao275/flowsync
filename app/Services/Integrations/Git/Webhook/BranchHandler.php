<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Models\GitConnection;
use App\Models\TaskBranch;

/** `create` / `delete` of a branch (tags are ignored). A deleted branch keeps its row, marked, as history. */
class BranchHandler implements GitEventHandler
{
    public function __construct(private readonly GitLinker $linker) {}

    public function handle(GitConnection $connection, string $event, string $action, array $payload, string $delivery): string
    {
        if (($payload['ref_type'] ?? '') !== 'branch' || empty($payload['ref']) || $this->linker->isBot($payload)) {
            return GitWebhookProcessor::IGNORED;
        }

        $name = (string) $payload['ref'];
        $changed = false;

        foreach ($this->linker->links($connection, $this->linker->repoId($payload)) as $link) {
            if ($event === 'delete') {
                $changed = TaskBranch::query()->where('project_repository_id', $link->id)->where('name', $name)->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]) > 0 || $changed;

                continue;
            }

            $task = $this->linker->tasks($link, $this->linker->keysIn($name))->first();
            if ($task === null) {
                continue;
            }
            $row = TaskBranch::query()->firstOrNew(['project_repository_id' => $link->id, 'name' => $name]);
            if ($row->exists && $row->deleted_at === null) {
                continue;
            }
            $row->fill(['task_id' => $task->id, 'provider_ref' => 'refs/heads/'.$name, 'deleted_at' => null])->save();

            if ($this->linker->claim($delivery, $link, $task, 'branch', $name)) {
                $this->linker->emit($task, $link, $delivery, [
                    'kind' => 'branch', 'state' => 'created', 'title' => $name,
                    'url' => $this->linker->safeUrl(rtrim((string) $link->html_url, '/').'/tree/'.rawurlencode($name)),
                ]);
                $changed = true;
            }
        }

        return $changed ? GitWebhookProcessor::PROCESSED : GitWebhookProcessor::IGNORED;
    }
}
