<?php

namespace App\Services\Hrms\Approval;

use App\Enums\Hrms\ApprovalStepMode;
use App\Enums\Hrms\ApproverType;
use App\Models\Hrms\Shared\ApprovalTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Approval/HRMS — read, validate and save the editable chain of each domain.
 *
 * A domain with no (active) template row answers from `config/approvals.php`,
 * so the hard-coded behaviour is the floor: nothing depends on the seed having
 * run, and "reset to default" is just writing the config chain back.
 */
class ChainTemplates
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /** @return array<string, array<string, mixed>> */
    public function domains(): array
    {
        return config('approvals.domains', []);
    }

    public function knows(string $domain): bool
    {
        return array_key_exists($domain, $this->domains());
    }

    /**
     * The effective chain for a domain.
     *
     * @return array{domain: string, name: string, steps: list<array<string, mixed>>, sla_hours: int|null, reminder_before_hours: int|null, escalation_role_slug: string|null, customised: bool}
     */
    public function for(string $domain): array
    {
        $default = $this->domains()[$domain] ?? null;
        abort_if($default === null, 404, 'Unknown approval domain.');

        $row = ApprovalTemplate::query()->where('domain', $domain)->where('is_active', true)->first();

        if ($row === null) {
            return [
                'domain' => $domain,
                'name' => $default['label'],
                'steps' => $default['steps'],
                'sla_hours' => null,
                'reminder_before_hours' => null,
                'escalation_role_slug' => null,
                'customised' => false,
            ];
        }

        return [
            'domain' => $domain,
            'name' => $row->name,
            'steps' => $row->steps,
            'sla_hours' => $row->sla_hours,
            'reminder_before_hours' => $row->reminder_before_hours,
            'escalation_role_slug' => $row->escalation_role_slug,
            'customised' => $row->steps !== $default['steps'] || $row->sla_hours !== null,
        ];
    }

    /**
     * Validate and persist a chain.
     *
     * @param  array{steps: list<array<string, mixed>>, sla_hours?: int|null, reminder_before_hours?: int|null, escalation_role_slug?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function save(string $domain, array $data, ?User $actor): ApprovalTemplate
    {
        abort_unless($this->knows($domain), 404, 'Unknown approval domain.');

        $steps = $this->normalize($domain, $data['steps'] ?? []);
        $slug = $data['escalation_role_slug'] ?? null;

        if ($slug !== null && ! Role::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['escalation_role_slug' => 'That role does not exist.']);
        }

        $row = ApprovalTemplate::query()->firstOrNew(['domain' => $domain]);
        $before = $row->exists ? ['steps' => $row->steps, 'sla_hours' => $row->sla_hours] : null;

        $row->fill([
            'name' => $this->domains()[$domain]['label'],
            'steps' => $steps,
            'sla_hours' => $data['sla_hours'] ?? null,
            'reminder_before_hours' => $data['reminder_before_hours'] ?? null,
            'escalation_role_slug' => $slug,
            'is_active' => true,
            'updated_by_user_id' => $actor?->id,
        ])->save();

        $this->audit->log($row, 'approval.template_updated', $before, ['steps' => $steps, 'sla_hours' => $row->sla_hours], $actor);

        return $row;
    }

    /** Write the shipped default chain back for a domain. */
    public function reset(string $domain, ?User $actor): ApprovalTemplate
    {
        abort_unless($this->knows($domain), 404, 'Unknown approval domain.');

        return $this->save($domain, ['steps' => $this->domains()[$domain]['steps']], $actor);
    }

    /**
     * Check the step definitions against the domain and the tenant's data and
     * return them in canonical form.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function normalize(string $domain, array $steps): array
    {
        if ($steps === []) {
            throw ValidationException::withMessages(['steps' => 'A chain needs at least one step.']);
        }

        $fields = $this->domains()[$domain]['fields'] ?? [];
        $out = [];
        $lastStage = null;

        foreach (array_values($steps) as $i => $raw) {
            $key = "steps.$i";
            $type = ApproverType::tryFrom((string) ($raw['type'] ?? ''));

            if ($type === null) {
                throw ValidationException::withMessages(["$key.type" => 'Choose who approves this step.']);
            }

            $step = ['type' => $type->value];
            $this->applyTarget($step, $raw, $type, $key);
            $this->applyStage($step, $raw, $key, $lastStage);

            if (isset($raw['sla_hours'])) {
                $step['sla_hours'] = max(1, (int) $raw['sla_hours']);
            }

            if (! empty($raw['when'])) {
                $step['when'] = $this->condition($raw['when'], $fields, $key);
            }

            $out[] = $step;
        }

        return $out;
    }

    /** @param array<string, mixed> $step @param array<string, mixed> $raw */
    private function applyTarget(array &$step, array $raw, ApproverType $type, string $key): void
    {
        if ($type === ApproverType::Role) {
            $slug = $raw['role_slug'] ?? null;
            $permission = $raw['permission'] ?? null;

            if ($slug !== null && Role::query()->where('slug', $slug)->exists()) {
                $step['role_slug'] = $slug;
            } elseif ($permission !== null && Permission::query()->where('slug', $permission)->exists()) {
                $step['permission'] = $permission;
            } else {
                throw ValidationException::withMessages(["$key.role_slug" => 'Choose an existing role for this step.']);
            }

            $step['omit_if_requester_holds'] = (bool) ($raw['omit_if_requester_holds'] ?? false);
        }

        if ($type === ApproverType::User) {
            $userId = (int) ($raw['user_id'] ?? 0);

            if (! User::query()->whereKey($userId)->exists()) {
                throw ValidationException::withMessages(["$key.user_id" => 'Choose a person for this step.']);
            }

            $step['user_id'] = $userId;
        }
    }

    /** @param array<string, mixed> $step @param array<string, mixed> $raw */
    private function applyStage(array &$step, array $raw, string $key, ?int &$lastStage): void
    {
        $mode = ApprovalStepMode::tryFrom((string) ($raw['mode'] ?? 'sequential'));

        if ($mode === null) {
            throw ValidationException::withMessages(["$key.mode" => 'Unknown step mode.']);
        }

        if (isset($raw['stage'])) {
            $stage = (int) $raw['stage'];

            if ($lastStage !== null && $stage < $lastStage) {
                throw ValidationException::withMessages(["$key.stage" => 'Stages must not go backwards.']);
            }

            $step['stage'] = $lastStage = $stage;
        }

        if ($mode->isParallel()) {
            $step['mode'] = $mode->value;
        }
    }

    /**
     * @param  array<string, mixed>  $when
     * @param  list<string>  $fields
     * @return array{field: string, op: string, value: float|int}
     */
    private function condition(array $when, array $fields, string $key): array
    {
        $field = (string) ($when['field'] ?? '');
        $op = (string) ($when['op'] ?? '');

        if (! in_array($field, $fields, true)) {
            throw ValidationException::withMessages(["$key.when.field" => 'That field is not available for this domain.']);
        }

        if (! in_array($op, config('approvals.operators', []), true) || ! is_numeric($when['value'] ?? null)) {
            throw ValidationException::withMessages(["$key.when.op" => 'Use a comparison and a number.']);
        }

        return ['field' => $field, 'op' => $op, 'value' => $when['value'] + 0];
    }
}
