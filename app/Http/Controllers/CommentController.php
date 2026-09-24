<?php

namespace App\Http\Controllers;

use App\Events\CommentSynced;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json([
            'comments' => $this->presentTree($task),
        ]);
    }

    public function store(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('create', [Comment::class, $task]);

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $parent = $this->resolveParent($task, $data['parent_id'] ?? null);

        $comment = Comment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'parent_id' => $parent?->id,
            'comment' => $data['comment'],
        ]);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.commented',
            data: ['comment_id' => $comment->id, 'snippet' => mb_strimwidth($data['comment'], 0, 120, '…')],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        $this->notifications->taskCommented($request->user(), $task, $comment);

        broadcast(new CommentSynced($comment, 'created'));

        return response()->json([
            'message' => 'Comment added.',
            'comment' => $this->present($comment->load('user', 'task:id,project_id')),
        ], 201);
    }

    public function update(Request $request, Project $project, Task $task, Comment $comment): JsonResponse
    {
        if ($comment->task_id !== $task->id || $comment->trashed()) {
            abort(404);
        }

        $this->authorize('update', $comment);

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
        ]);

        $comment->update([
            'comment' => $data['comment'],
            'edited_at' => now(),
        ]);

        broadcast(new CommentSynced($comment->fresh(), 'updated'));

        return response()->json([
            'message' => 'Comment updated.',
            'comment' => $this->present($comment->fresh()->load('user', 'task:id,project_id')),
        ]);
    }

    public function destroy(Request $request, Project $project, Task $task, Comment $comment): JsonResponse
    {
        if ($comment->task_id !== $task->id || $comment->trashed()) {
            abort(404);
        }

        $this->authorize('delete', $comment);

        $comment->delete();

        broadcast(new CommentSynced($comment, 'deleted'));

        return response()->json(['message' => 'Comment deleted.']);
    }

    private function resolveParent(Task $task, $parentId): ?Comment
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        if ($task->comments()->whereKey($parentId)->whereNull('parent_id')->exists()) {
            return Comment::whereKey($parentId)->first();
        }

        throw ValidationException::withMessages([
            'parent_id' => 'The selected comment is not a valid reply target on this task.',
        ]);
    }

    private function presentTree(Task $task): array
    {
        $top = $task->comments()
            ->whereNull('parent_id')
            ->with('user')
            ->orderBy('created_at')
            ->get();

        return $top
            ->map(function (Comment $comment) {
                $comment->load('replies.user');

                return array_merge($this->present($comment), [
                    'replies' => $comment->replies
                        ->sortBy('created_at')
                        ->values()
                        ->map(fn (Comment $reply) => $this->present($reply)),
                ]);
            })
            ->values()
            ->all();
    }

    private function present(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'comment' => $comment->comment,
            'edited_at' => $comment->edited_at?->toISOString(),
            'deleted_at' => $comment->deleted_at?->toISOString(),
            'created_at' => $comment->created_at?->toISOString(),
            'user' => $comment->user ? [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'email' => $comment->user->email,
            ] : null,
        ];
    }
}
