<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomFieldIndexRequest;
use App\Models\CustomFieldDefinition;
use App\Services\CustomFields\CustomFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Custom-field definitions. Reading them is open to every signed-in tenant user (forms need the
 * list to render their inputs); defining, editing and removing them needs `custom_fields.manage`.
 */
class CustomFieldController extends Controller
{
    public function __construct(private readonly CustomFieldService $fields) {}

    public function index(CustomFieldIndexRequest $request): JsonResponse
    {
        $data = $request->validated();

        $manage = $request->user()->hasPermission('custom_fields.manage');
        $definitions = $this->fields->definitions($data['entity'], ! ($manage && $request->boolean('include_inactive')));

        return response()->json([
            'fields' => $definitions->map(fn (CustomFieldDefinition $d) => $this->present($d, $manage))->values(),
            'types' => CustomFieldService::TYPES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $definition = $this->fields->create($data);

        return response()->json(['message' => __('Custom field created.'), 'field' => $this->present($definition, true)], 201);
    }

    public function update(Request $request, CustomFieldDefinition $field): JsonResponse
    {
        $data = $this->validated($request, creating: false);

        return response()->json([
            'message' => __('Custom field updated.'),
            'field' => $this->present($this->fields->update($field, $data), true),
        ]);
    }

    public function destroy(CustomFieldDefinition $field): JsonResponse
    {
        $this->fields->delete($field);

        return response()->json(['message' => __('Custom field deleted.')]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'options' => ['sometimes', 'nullable', 'array'],
            'required' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
            'is_sensitive' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'help_text' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        if ($creating) {
            $rules += [
                'entity' => ['required', Rule::in(CustomFieldService::ENTITIES)],
                'type' => ['required', Rule::in(CustomFieldService::TYPES)],
                'key' => ['sometimes', 'nullable', 'string', 'max:56', 'regex:/^[a-z][a-z0-9_]*$/'],
            ];
        }

        return $request->validate($rules);
    }

    /** @return array<string, mixed> */
    private function present(CustomFieldDefinition $d, bool $manage): array
    {
        return [
            'id' => $d->id,
            'entity' => $d->entity,
            'key' => $d->key,
            'label' => $d->label,
            'type' => $d->type,
            'options' => $d->options,
            'required' => $d->required,
            'help_text' => $d->help_text,
            'position' => $d->position,
        ] + ($manage ? [
            'is_filterable' => $d->is_filterable,
            'is_sensitive' => $d->is_sensitive,
            'is_active' => $d->is_active,
        ] : []);
    }
}
