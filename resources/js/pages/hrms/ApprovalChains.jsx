import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';

const blankStep = { type: 'manager' };

function describeStep(step, options) {
    const type = options.step_types.find((t) => t.value === step.type)?.label ?? step.type;
    const role = step.role_slug ? options.roles.find((r) => r.slug === step.role_slug)?.name ?? step.role_slug : null;
    const parts = [role ? `${type}: ${role}` : type];

    if (step.mode && step.mode !== 'sequential') parts.push(step.mode === 'parallel_any' ? 'any of group' : 'all of group');
    if (step.when) parts.push(`only if ${step.when.field} ${step.when.op} ${step.when.value}`);
    if (step.sla_hours) parts.push(`${step.sla_hours}h`);

    return parts.join(' · ');
}

/**
 * Who approves what: the tenant's editable approval chain for each kind of
 * request (leave, expenses, regularization, comp-off, salary revisions).
 *
 * Changing a chain only affects requests filed afterwards — approvals already
 * in flight keep the steps they were opened with. The server is the judge of
 * a chain (roles must exist, conditions must use the domain's own fields);
 * this page only collects it and shows the server's per-step errors.
 */
export default function ApprovalChains() {
    usePageTitle('Approval chains');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(null);
    const [draft, setDraft] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [resetting, setResetting] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Approval chains' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);

        return api
            .get('/hrms/approvals/chains')
            .then(({ data: response }) => setData(response))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError('Unable to load the approval chains.');
            });
    }, [navigate]);

    useEffect(() => {
        load();
    }, [load]);

    function open(chain) {
        setEditing(chain);
        setErrors({});
        setDraft({
            steps: chain.steps.map((s) => ({ ...s })),
            sla_hours: chain.sla_hours ?? '',
            reminder_before_hours: chain.reminder_before_hours ?? '',
            escalation_role_slug: chain.escalation_role_slug ?? '',
        });
    }

    function patchStep(index, patch) {
        setDraft((prev) => ({
            ...prev,
            steps: prev.steps.map((s, i) => (i === index ? { ...s, ...patch } : s)),
        }));
    }

    function move(index, delta) {
        setDraft((prev) => {
            const steps = [...prev.steps];
            const target = index + delta;
            if (target < 0 || target >= steps.length) return prev;
            [steps[index], steps[target]] = [steps[target], steps[index]];
            return { ...prev, steps };
        });
    }

    function payload() {
        const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

        return {
            steps: draft.steps.map((s) => {
                const out = { type: s.type };
                if (s.type === 'role') {
                    out.role_slug = s.role_slug || null;
                    out.permission = s.role_slug ? null : s.permission ?? null;
                    out.omit_if_requester_holds = !!s.omit_if_requester_holds;
                }
                if (num(s.stage)) out.stage = num(s.stage);
                if (s.mode && s.mode !== 'sequential') out.mode = s.mode;
                if (num(s.sla_hours)) out.sla_hours = num(s.sla_hours);
                if (s.when && s.when.field) out.when = { field: s.when.field, op: s.when.op || '>=', value: num(s.when.value) };
                return out;
            }),
            sla_hours: num(draft.sla_hours),
            reminder_before_hours: num(draft.reminder_before_hours),
            escalation_role_slug: draft.escalation_role_slug || null,
        };
    }

    function save() {
        setSaving(true);
        setErrors({});

        api.put(`/hrms/approvals/chains/${editing.domain}`, payload())
            .then(({ data: response }) => {
                toast.success(response.message);
                setEditing(null);
                return load();
            })
            .catch((err) => setErrors(fieldErrors(err)))
            .finally(() => setSaving(false));
    }

    function reset() {
        const chain = resetting;
        setResetting(null);

        api.post(`/hrms/approvals/chains/${chain.domain}/reset`)
            .then(({ data: response }) => {
                toast.success(response.message);
                return load();
            })
            .catch((err) => toast.error(fieldErrors(err).form ?? 'Unable to reset the chain.'));
    }

    if (error) return <Alert type="error">{error}</Alert>;
    if (!data) return <Spinner />;

    const { options } = data;

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">Approval chains</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    Who approves each kind of request, in which order. Changes apply to requests filed from now on;
                    approvals already in flight keep their steps.
                </p>
            </div>

            {data.chains.map((chain) => (
                <Card
                    key={chain.domain}
                    title={chain.label}
                    subtitle={chain.customised ? 'Customised' : 'Default chain'}
                    actions={
                        <div className="flex gap-2">
                            <Button size="sm" variant="secondary" onClick={() => open(chain)}>
                                Edit
                            </Button>
                            {chain.customised && (
                                <Button size="sm" variant="ghost" onClick={() => setResetting(chain)}>
                                    Reset
                                </Button>
                            )}
                        </div>
                    }
                >
                    <ol className="list-decimal space-y-1 pl-5 text-sm text-gray-700">
                        {chain.steps.map((step, i) => (
                            <li key={i}>{describeStep(step, options)}</li>
                        ))}
                    </ol>
                    {chain.sla_hours && (
                        <p className="mt-3 text-xs text-gray-500">
                            Each stage is due in {chain.sla_hours}h; escalates to{' '}
                            {chain.escalation_role_slug ?? 'the HR manager role'} when overdue.
                        </p>
                    )}
                </Card>
            ))}

            <Modal open={!!resetting} onClose={() => setResetting(null)} title="Reset to default?" size="sm">
                <p className="text-sm text-gray-600">
                    “{resetting?.label}” goes back to the shipped chain. Approvals already in flight are not affected.
                </p>
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="secondary" onClick={() => setResetting(null)}>
                        Cancel
                    </Button>
                    <Button variant="danger" onClick={reset}>
                        Reset chain
                    </Button>
                </div>
            </Modal>

            <Modal
                open={!!editing}
                onClose={() => setEditing(null)}
                title={editing ? `Edit: ${editing.label}` : ''}
                size="lg"
            >
                {draft && (
                    <div className="space-y-4">
                        {errors.form && <Alert type="error">{errors.form}</Alert>}
                        {errors.steps && <Alert type="error">{errors.steps}</Alert>}

                        {draft.steps.map((step, i) => (
                            <div key={i} className="space-y-3 rounded-lg border border-gray-200 p-3">
                                <div className="flex items-center justify-between">
                                    <span className="text-sm font-medium text-gray-700">Step {i + 1}</span>
                                    <div className="flex gap-1">
                                        <Button size="sm" variant="ghost" onClick={() => move(i, -1)} disabled={i === 0}>
                                            Up
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => move(i, 1)} disabled={i === draft.steps.length - 1}>
                                            Down
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            disabled={draft.steps.length === 1}
                                            onClick={() => setDraft((p) => ({ ...p, steps: p.steps.filter((_, k) => k !== i) }))}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                                    <Select label="Approver" value={step.type} onChange={(e) => patchStep(i, { type: e.target.value })} error={errors[`steps.${i}.type`]}>
                                        {options.step_types
                                            .filter((t) => t.value !== 'user')
                                            .map((t) => (
                                                <option key={t.value} value={t.value}>{t.label}</option>
                                            ))}
                                    </Select>
                                    {step.type === 'role' && (
                                        <Select
                                            label="Role"
                                            value={step.role_slug ?? ''}
                                            onChange={(e) => patchStep(i, { role_slug: e.target.value })}
                                            error={errors[`steps.${i}.role_slug`]}
                                        >
                                            <option value="">Choose…</option>
                                            {options.roles.map((r) => (
                                                <option key={r.slug} value={r.slug}>{r.name}</option>
                                            ))}
                                        </Select>
                                    )}
                                    <Input
                                        label="Group (steps sharing a number decide together)"
                                        type="number"
                                        min="1"
                                        value={step.stage ?? ''}
                                        onChange={(e) => patchStep(i, { stage: e.target.value })}
                                        error={errors[`steps.${i}.stage`]}
                                    />
                                    <Select label="Group mode" value={step.mode ?? 'sequential'} onChange={(e) => patchStep(i, { mode: e.target.value })}>
                                        {options.modes.map((m) => (
                                            <option key={m.value} value={m.value}>{m.label}</option>
                                        ))}
                                    </Select>
                                    <Input
                                        label="Due in (hours)"
                                        type="number"
                                        min="1"
                                        value={step.sla_hours ?? ''}
                                        onChange={(e) => patchStep(i, { sla_hours: e.target.value })}
                                    />
                                </div>

                                <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                                    <Select
                                        label="Only when…"
                                        value={step.when?.field ?? ''}
                                        onChange={(e) => patchStep(i, { when: e.target.value ? { field: e.target.value, op: step.when?.op ?? '>=', value: step.when?.value ?? '' } : null })}
                                        error={errors[`steps.${i}.when.field`]}
                                    >
                                        <option value="">Always</option>
                                        {editing.fields.map((f) => (
                                            <option key={f} value={f}>{f}</option>
                                        ))}
                                    </Select>
                                    {step.when?.field && (
                                        <>
                                            <Select label="Is" value={step.when.op ?? '>='} onChange={(e) => patchStep(i, { when: { ...step.when, op: e.target.value } })}>
                                                {options.operators.map((op) => (
                                                    <option key={op} value={op}>{op}</option>
                                                ))}
                                            </Select>
                                            <Input
                                                label="Value"
                                                type="number"
                                                value={step.when.value ?? ''}
                                                onChange={(e) => patchStep(i, { when: { ...step.when, value: e.target.value } })}
                                                error={errors[`steps.${i}.when.op`]}
                                            />
                                        </>
                                    )}
                                </div>
                            </div>
                        ))}

                        <Button
                            variant="secondary"
                            size="sm"
                            disabled={draft.steps.length >= 8}
                            onClick={() => setDraft((p) => ({ ...p, steps: [...p.steps, { ...blankStep }] }))}
                        >
                            Add a step
                        </Button>

                        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                            <Input
                                label="Default stage deadline (hours)"
                                type="number"
                                min="1"
                                value={draft.sla_hours}
                                onChange={(e) => setDraft((p) => ({ ...p, sla_hours: e.target.value }))}
                            />
                            <Input
                                label={`Remind before due (hours, default ${options.default_reminder_hours})`}
                                type="number"
                                min="0"
                                value={draft.reminder_before_hours}
                                onChange={(e) => setDraft((p) => ({ ...p, reminder_before_hours: e.target.value }))}
                            />
                            <Select
                                label="Escalate overdue to"
                                value={draft.escalation_role_slug}
                                onChange={(e) => setDraft((p) => ({ ...p, escalation_role_slug: e.target.value }))}
                                error={errors.escalation_role_slug}
                            >
                                <option value="">HR manager (default)</option>
                                {options.roles.map((r) => (
                                    <option key={r.slug} value={r.slug}>{r.name}</option>
                                ))}
                            </Select>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button loading={saving} onClick={save}>
                                Save chain
                            </Button>
                        </div>
                    </div>
                )}
            </Modal>
        </div>
    );
}
