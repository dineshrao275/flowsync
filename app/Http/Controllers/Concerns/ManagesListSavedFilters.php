<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saved filters for listing pages, shared by the tenant and platform controllers. A filter is
 * `{page, name, filters{}}`: `page` is the list's slug, `filters` a flat map of scalars/arrays the page understands.
 * Anyone signed in may keep private filters; sharing with the whole tenant/platform is limited to `canShare()`, and a
 * shared filter can be changed or deleted by its owner or by a `canShare()` holder. Somebody else's private filter does
 * not exist as far as the API is concerned (404).
 */
trait ManagesListSavedFilters
{
    private const MAX_PER_PAGE_PER_USER = 50;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function canShare(Request $request): bool;

    public function index(Request $request): JsonResponse
    {
        $page = $this->pageKey($request);
        $me = $request->user()->id;

        $filters = $this->model()::query()->with('owner:id,name')
            ->where('page', $page)
            ->where(fn ($q) => $q->where('owner_user_id', $me)->orWhere('is_shared', true))
            ->orderBy('name')->get();

        return response()->json(['filters' => $filters->map(fn ($f) => $this->present($f, $request))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $me = $request->user()->id;

        abort_if($this->model()::where('page', $data['page'])->where('owner_user_id', $me)->count() >= self::MAX_PER_PAGE_PER_USER, 422, __('You can save up to 50 filters per page.'));
        abort_if(! empty($data['is_shared']) && ! $this->canShare($request), 403, __('You are not allowed to share filters.'));

        $filter = $this->model()::create($data + ['is_shared' => false, 'owner_user_id' => $me]);

        return response()->json(['message' => __('Filter saved.'), 'filter' => $this->present($filter->load('owner:id,name'), $request)], 201);
    }

    public function update(Request $request, int $filter): JsonResponse
    {
        $row = $this->findManageable($request, $filter);
        $data = $this->validated($request, partial: true);
        unset($data['page']); // a filter never moves between lists

        abort_if(($data['is_shared'] ?? $row->is_shared) && ! $row->is_shared && ! $this->canShare($request), 403, __('You are not allowed to share filters.'));

        $row->update($data);

        return response()->json(['message' => __('Filter updated.'), 'filter' => $this->present($row->refresh()->load('owner:id,name'), $request)]);
    }

    public function destroy(Request $request, int $filter): JsonResponse
    {
        $this->findManageable($request, $filter)->delete();

        return response()->json(['message' => __('Filter deleted.')]);
    }

    private function findManageable(Request $request, int $id): Model
    {
        $row = $this->model()::findOrFail($id);

        if ((int) $row->owner_user_id === (int) $request->user()->id) {
            return $row;
        }

        abort_unless($row->is_shared, 404);
        abort_unless($this->canShare($request), 403, __('Only the owner or an administrator can change a shared filter.'));

        return $row;
    }

    private function pageKey(Request $request): string
    {
        return $request->validate(['page' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9._-]*$/']])['page'];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'page' => [$partial ? 'sometimes' : 'required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'filters' => [$partial ? 'sometimes' : 'required', 'array', 'max:40'],
            'filters.*' => ['nullable'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['filters'])) {
            $clean = array_filter($data['filters'], fn ($v) => $v !== null && $v !== '' && $v !== []);
            // Plain data only: scalars and flat lists of scalars, and a hard size cap so this is not a blob store.
            foreach ($clean as $key => $value) {
                $ok = is_scalar($value) || (is_array($value) && collect($value)->every(fn ($v) => is_scalar($v)));
                abort_unless($ok && is_string($key) && strlen($key) <= 60, 422, __('Those filters cannot be saved.'));
            }
            abort_if(strlen(json_encode($clean)) > 4000, 422, __('Those filters cannot be saved.'));
            $data['filters'] = $clean;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Model $f, Request $request): array
    {
        return [
            'id' => $f->id, 'page' => $f->page, 'name' => $f->name,
            'filters' => (object) ($f->filters ?? []),
            'is_shared' => $f->is_shared,
            'owner' => $f->owner ? ['id' => $f->owner->id, 'name' => $f->owner->name] : null,
            'mine' => (int) $f->owner_user_id === (int) $request->user()->id,
            'can_manage' => (int) $f->owner_user_id === (int) $request->user()->id || ($f->is_shared && $this->canShare($request)),
        ];
    }
}
