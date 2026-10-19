<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Shared\RecordComment;
use App\Services\HrmsAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comments on HR records (F2.6). Whoever may *view* the record may read and write its thread — the record's
 * own policy decides, so the scope rules (own / assigned / all) carry over without a second definition.
 * Comments are append-only: no edit, no delete.
 */
class RecordCommentController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const TYPES = ['expense' => ExpenseClaim::class, 'leave' => LeaveRequest::class];

    public function __construct(private readonly HrmsAuditLogger $audit) {}

    public function index(string $type, int $id): JsonResponse
    {
        [$record] = $this->resolve($type, $id);

        $comments = RecordComment::query()->where('commentable_type', $type)->where('commentable_id', $record->getKey())
            ->with('author:id,name')->orderBy('id')->limit(200)->get();

        return response()->json(['comments' => $comments->map(fn ($c) => $this->present($c))->values()]);
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        [$record] = $this->resolve($type, $id);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $comment = RecordComment::create([
            'commentable_type' => $type, 'commentable_id' => $record->getKey(), 'user_id' => $request->user()->id, 'body' => trim($data['body']),
        ]);
        $this->audit->log($record, "$type.commented", null, ['comment_id' => $comment->id], $request->user());

        return response()->json(['message' => __('Comment added.'), 'comment' => $this->present($comment->load('author:id,name'))], 201);
    }

    /** @return array{0: Model} */
    private function resolve(string $type, int $id): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $record = self::TYPES[$type]::query()->findOrFail($id);
        $this->authorize('view', $record);

        return [$record];
    }

    /** @return array<string, mixed> */
    private function present(RecordComment $c): array
    {
        return ['id' => $c->id, 'body' => $c->body, 'author' => $c->author?->name, 'user_id' => $c->user_id, 'created_at' => $c->created_at?->toIso8601String()];
    }
}
