<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachmentStoreRequest;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json([
            'attachments' => $task->attachments()
                ->with('user')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Attachment $attachment) => $this->present($attachment)),
        ]);
    }

    public function store(AttachmentStoreRequest $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('create', [Attachment::class, $task]);

        $data = $request->validated();

        $file = $data['file'];
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
            'attachment' => $this->present($attachment->load('user')),
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

    public function download(Task $task, Attachment $attachment): StreamedResponse
    {
        if ($attachment->task_id !== $task->id) {
            abort(404);
        }

        if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
            abort(404, 'File no longer exists.');
        }

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    private function present(Attachment $attachment): array
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
                ['task' => $attachment->task_id, 'attachment' => $attachment->id],
            ),
        ];
    }
}
