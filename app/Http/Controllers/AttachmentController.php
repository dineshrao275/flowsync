<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachmentStoreRequest;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly TenantContext $tenantContext,
        private readonly TenantLimits $limits,
    ) {}

    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json([
            'attachments' => $task->attachments()
                ->with('user')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Attachment $attachment) => $this->present($attachment, $request->user())),
        ]);
    }

    public function store(AttachmentStoreRequest $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('create', [Attachment::class, $task]);

        $data = $request->validated();
        $file = $data['file'];

        // Phase 5 E6: enforce the plan's storage_bytes quota before writing.
        // TenantLimits::assertQuota() handles row counts; bytes need a SUM.
        $this->assertStorageQuota($file->getSize());

        $extension = $file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        $storedName = Str::uuid().'.'.$extension;
        $path = $file->storeAs(
            "tasks/{$task->project_id}/{$task->id}",
            $storedName,
            ['disk' => 'local'],
        );

        $attachment = Attachment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'stored_name' => $storedName,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'disk' => 'local',
            'path' => $path,
        ]);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.attachment_created',
            data: ['attachment_id' => $attachment->id, 'name' => $attachment->original_name, 'size' => $attachment->size],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'message' => 'File uploaded.',
            'attachment' => $this->present($attachment->load('user'), $request->user()),
        ], 201);
    }

    public function destroy(Request $request, Project $project, Task $task, Attachment $attachment): JsonResponse
    {
        if ($attachment->task_id !== $task->id) {
            abort(404);
        }

        $this->authorize('delete', $attachment);

        $name = $attachment->original_name;

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.attachment_deleted',
            data: ['attachment_id' => $attachment->id, 'name' => $name],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        return response()->json(['message' => 'Attachment deleted.']);
    }

    /**
     * The download link is deliberately session-free: it is opened in a fresh
     * browser tab, so it runs outside the auth/tenant middleware groups and no
     * SwitchTenant has connected a tenant database. The task/attachment ids are
     * therefore per-tenant-local and mean nothing on the central connection,
     * which is why the central tenant id travels inside the signed URL and the
     * lookup happens inside TenantDatabaseManager::using().
     */
    public function download(Request $request, int $task, int $attachment): StreamedResponse
    {
        $tenant = Tenant::find((int) $request->query('tenant'));

        abort_if($tenant === null, 404);

        $actor = $request->query('actor');

        return app(TenantDatabaseManager::class)->using($tenant, function () use ($task, $attachment, $actor) {
            $record = Attachment::query()
                ->where('task_id', $task)
                ->where('id', $attachment)
                ->first();

            abort_if($record === null, 404);

            // The file belongs to whoever may open the task (TaskPolicy::view —
            // same rule as the JSON show). The reader rides inside the signature
            // because the route has no session; nobody anonymous, even with a
            // valid signature: a forwarded link is a bearer token.
            $reader = $actor === null ? null : User::find((int) $actor);
            abort_if($reader === null, 403, 'This download needs a signed reader.');
            $reader->loadMissing('roles.permissions');
            Gate::forUser($reader)->authorize('view', $record->task);

            if (! Storage::disk($record->disk)->exists($record->path)) {
                abort(404, 'File no longer exists.');
            }

            return Storage::disk($record->disk)->download($record->path, $record->original_name);
        });
    }

    private function present(Attachment $attachment, User $reader): array
    {
        return [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'created_at' => $attachment->created_at?->toISOString(),
            'user' => $attachment->user ? [
                'id' => $attachment->user->id,
                'name' => $attachment->user->name,
            ] : null,
            'download_url' => url()->temporarySignedRoute(
                'attachments.download',
                now()->addHours(1),
                [
                    'task' => $attachment->task_id,
                    'attachment' => $attachment->id,
                    'tenant' => $this->tenantContext->currentId(),
                    // The reader rides inside the signature: the download
                    // route has no session, and the stream refuses anonymous
                    // holders even with a valid signature.
                    'actor' => $reader->id,
                ],
            ),
        ];
    }

    /**
     * Throw a 422 ValidationException when adding $newBytes to the current
     * used storage would exceed the plan's storage_bytes limit.
     *
     * Called before writing the file to disk — quota enforcement must happen
     * before the side-effect, not after.
     */
    private function assertStorageQuota(int $newBytes): void
    {
        $tenantId = $this->tenantContext->currentId();

        if ($tenantId === null) {
            // Provisioning / seeder / test context — skip.
            return;
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return;
        }

        $limitBytes = $this->limits->limit($tenant, 'storage_bytes');

        if ($limitBytes === null) {
            // No limit configured for this plan → unlimited.
            return;
        }

        $usedBytes = Attachment::sum('size');

        if (($usedBytes + $newBytes) > $limitBytes) {
            $usedGb = round($usedBytes / (1024 ** 3), 2);
            $limitGb = round($limitBytes / (1024 ** 3), 2);

            throw ValidationException::withMessages([
                'file' => "Storage quota exceeded. Your plan allows {$limitGb} GB; {$usedGb} GB is already in use. "
                    .'Delete unused attachments or upgrade your plan.',
            ]);
        }
    }
}
