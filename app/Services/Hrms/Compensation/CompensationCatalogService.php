<?php

namespace App\Services\Hrms\Compensation;

use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Payroll\SalaryStructure;
use App\Models\User;
use App\Services\Hrms\Org\OrgNaming;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compensation/HRMS — the pay-head and template master data.
 *
 * Split from {@see CompensationService} for the 300-line ceiling, not for
 * reuse: templates, assignments and CTC math stay there; everything a tenant
 * admin edits about *what heads exist and what a template holds* lives here.
 * Every mutation audits (identifiers and state only — amounts never enter
 * the ledger), and the two immutable families refuse: `is_system` starters
 * a tenant may rename but not repurpose, and `is_statutory` heads the P10
 * engine owns once it computes.
 */
class CompensationCatalogService
{
    public function __construct(
        private readonly OrgNaming $naming,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException on an unknown component type
     */
    public function createComponent(array $data, ?User $actor = null): SalaryComponent
    {
        $component = SalaryComponent::create([
            ...$data,
            'slug' => $this->naming->uniqueSlug(SalaryComponent::class, (string) $data['name']),
            'is_system' => false,
            'is_statutory' => false,
        ]);

        $this->audit->log($component, 'compensation.component_created', null, [
            'code' => $component->code,
            'type' => $component->type->value,
        ], $actor);

        return $component->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException on a system or statutory row
     */
    public function updateComponent(SalaryComponent $component, array $data, ?User $actor = null): SalaryComponent
    {
        $this->requireMutable($component);

        $before = ['name' => $component->name, 'is_active' => $component->is_active];
        $component->update($data);

        $this->audit->log($component->refresh(), 'compensation.component_updated', $before, [
            'name' => $component->name,
            'is_active' => $component->is_active,
        ], $actor);

        return $component->refresh();
    }

    /**
     * @throws ValidationException on a system/statutory row or one in use
     */
    public function deleteComponent(SalaryComponent $component, ?User $actor = null): void
    {
        $this->requireMutable($component);

        if ($component->structures()->exists()) {
            throw ValidationException::withMessages(['form' => 'That head is on a salary structure — remove it there first.']);
        }

        $this->audit->log($component, 'compensation.component_deleted', [
            'code' => $component->code,
        ], null, $actor);

        $component->delete();
    }

    /**
     * Rename, describe, or (de)activate a template. The money knobs
     * (`currency`, `effective_from`) are immutable — a template versions by
     * effective date instead of being edited under its assignments.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateStructure(SalaryStructure $structure, array $data, ?User $actor = null): SalaryStructure
    {
        $before = ['name' => $structure->name, 'is_active' => $structure->is_active];
        $structure->update($data);

        $this->audit->log($structure->refresh(), 'compensation.structure_updated', $before, [
            'name' => $structure->name,
            'is_active' => $structure->is_active,
        ], $actor);

        return $structure->refresh();
    }

    /**
     * @throws ValidationException while assignments reference the template
     */
    public function deleteStructure(SalaryStructure $structure, ?User $actor = null): void
    {
        if ($structure->assignments()->exists()) {
            throw ValidationException::withMessages(['form' => 'That structure prices people — deactivate it instead of deleting history.']);
        }

        $this->audit->log($structure, 'compensation.structure_deleted', [
            'slug' => $structure->slug,
        ], null, $actor);

        $structure->delete();
    }

    /**
     * Replace a template's head list wholesale: one call names the full set,
     * so a payload cannot drag a row out of a different template (the
     * OrgNaming::order lesson) and omitted heads are detached, not orphaned.
     *
     * @param  list<array{component_id: int, value?: numeric|null, sequence?: int|null}>  $links
     *
     * @throws ValidationException on an unknown head
     */
    public function setStructureComponents(SalaryStructure $structure, array $links, ?User $actor = null): SalaryStructure
    {
        $sync = [];

        foreach ($links as $index => $link) {
            $component = SalaryComponent::find($link['component_id'] ?? null);

            if ($component === null) {
                throw ValidationException::withMessages(['components' => 'A linked head does not exist.']);
            }

            $sync[$component->id] = [
                'value' => $link['value'] ?? $component->default_value,
                'sequence' => $link['sequence'] ?? ($index + 1) * 10,
                'is_override' => true,
            ];
        }

        DB::transaction(function () use ($structure, $sync, $actor): void {
            $structure->components()->sync($sync);

            $this->audit->log($structure, 'compensation.structure_components_set', null, [
                'components' => count($sync),
            ], $actor);
        });

        return $structure->refresh();
    }

    /**
     * @throws ValidationException on a system or statutory row
     */
    private function requireMutable(SalaryComponent $component): void
    {
        if ($component->is_system) {
            throw ValidationException::withMessages(['form' => 'That is a starter head — it can be renamed, not repurposed.']);
        }

        if ($component->is_statutory) {
            throw ValidationException::withMessages(['form' => 'That head belongs to the statutory engine — it is not hand-edited.']);
        }
    }
}
