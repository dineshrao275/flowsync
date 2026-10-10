import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import MetricCard from '../components/ui/MetricCard';
import StatusPill from '../components/ui/StatusPill';
import LimitsEditor, { limitValue, formatBytes } from '../components/billing/LimitsEditor';
import { useToast } from '../context/ToastContext';
import { formatPrice } from '../utils/format';
import usePageTitle from '../hooks/usePageTitle';

const emptyForm = {
    product: 'suite',
    name: '',
    slug: '',
    description: '',
    billing_cycle: 'monthly',
    price_cents: 0,
    currency: 'USD',
    trial_duration_days: '',
    is_active: true,
    is_default: false,
    sort_order: 0,
};

const FEATURE_MATRIX_ROWS = [
    {
        feature: 'TMS · Projects & boards',
        starter: { type: 'included', label: 'Included' },
        professional: { type: 'included', label: 'Included' },
        business: { type: 'included', label: 'Included' },
        enterprise: { type: 'included', label: 'Included' },
    },
    {
        feature: 'HRMS · Employee records',
        starter: { type: 'none', label: '—' },
        professional: { type: 'included', label: 'Included' },
        business: { type: 'included', label: 'Included' },
        enterprise: { type: 'included', label: 'Included' },
    },
    {
        feature: 'Attendance & leave',
        starter: { type: 'none', label: '—' },
        professional: { type: 'core', label: 'Core' },
        business: { type: 'advanced', label: 'Advanced' },
        enterprise: { type: 'advanced', label: 'Advanced' },
    },
    {
        feature: 'Payroll processing',
        starter: { type: 'none', label: '—' },
        professional: { type: 'none', label: '—' },
        business: { type: 'core', label: 'Core' },
        enterprise: { type: 'advanced', label: 'Advanced' },
    },
    {
        feature: 'Automation executions',
        starter: { type: 'tier', label: '100 / mo' },
        professional: { type: 'tier', label: '1k / mo' },
        business: { type: 'tier', label: '10k / mo' },
        enterprise: { type: 'custom', label: 'Custom' },
    },
    {
        feature: 'SSO & audit retention',
        starter: { type: 'none', label: '—' },
        professional: { type: 'none', label: '—' },
        business: { type: 'tier', label: '90 days' },
        enterprise: { type: 'custom', label: 'Custom' },
    },
];

function PlanCell({ cell }) {
    if (cell.type === 'none') {
        return <span className="text-xs text-[#94a3b8]">—</span>;
    }
    if (cell.type === 'included') {
        return (
            <span className="inline-flex items-center rounded-full bg-[#e6f7ef] px-3 py-1 text-xs font-semibold text-[#1f9b69]">
                {cell.label}
            </span>
        );
    }
    if (cell.type === 'core' || cell.type === 'advanced') {
        return (
            <span className="inline-flex items-center rounded-full bg-[#e6f7ef] px-3 py-1 text-xs font-semibold text-[#1f9b69]">
                {cell.label}
            </span>
        );
    }
    return (
        <span className="inline-flex items-center rounded-full bg-[#f1f5f9] px-3 py-1 text-xs font-semibold text-[#475569] dark:bg-[#20283e] dark:text-[#cbd5e1]">
            {cell.label}
        </span>
    );
}

function LimitSummary({ plan }) {
    const cells = [
        ['Users', limitValue(plan.limits, 'users')],
        ['Workspaces', limitValue(plan.limits, 'workspaces')],
        ['Projects', limitValue(plan.limits, 'projects')],
        ['Tasks', limitValue(plan.limits, 'tasks')],
    ];

    return (
        <span className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-[#64748b]">
            {cells.map(([label, value]) => (
                <span key={label}>
                    <span className="tabular-nums font-semibold text-[#0f172a] dark:text-white">
                        {value === null ? '∞' : value}
                    </span>{' '}
                    {label.toLowerCase()}
                </span>
            ))}
            <span>
                <span className="font-semibold text-[#0f172a] dark:text-white">
                    {formatBytes(limitValue(plan.limits, 'storage_bytes'))}
                </span>{' '}
                storage
            </span>
        </span>
    );
}

function PlanForm({ initial, onSave, onCancel }) {
    const [form, setForm] = useState(initial);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    function set(field, value) {
        setForm((f) => ({ ...f, [field]: value }));
    }

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await onSave({
                ...form,
                trial_duration_days: form.trial_duration_days === '' ? null : form.trial_duration_days,
                limits: form.limits && Object.keys(form.limits).length ? form.limits : null,
            });
        } catch (e) {
            setErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={submit} className="space-y-4 px-6 pb-6 pt-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Input label="Name" name="name" value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name} required />
                <Input label="Slug" name="slug" value={form.slug} onChange={(e) => set('slug', e.target.value)} error={errors.slug} required />
            </div>
            <Input
                label="Description"
                name="description"
                value={form.description}
                onChange={(e) => set('description', e.target.value)}
                error={errors.description}
                placeholder="What this plan includes"
            />
            <LimitsEditor
                value={form.limits || {}}
                onChange={(limits) => set('limits', limits)}
                error={errors.limits}
            />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Input
                    label="Price (cents)"
                    name="price_cents"
                    type="number"
                    min="0"
                    value={form.price_cents}
                    onChange={(e) => set('price_cents', parseInt(e.target.value || '0', 10))}
                    error={errors.price_cents}
                    required
                />
                <div>
                    <label className="block text-xs font-semibold text-[#64748b] mb-1">Billing cycle</label>
                    <select
                        value={form.billing_cycle}
                        onChange={(e) => set('billing_cycle', e.target.value)}
                        className="w-full rounded-lg border border-[#e3e7f0] bg-white px-3 py-2 text-xs font-medium text-[#0f172a] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                    >
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <Input
                    label="Trial days"
                    name="trial_duration_days"
                    type="number"
                    min="0"
                    value={form.trial_duration_days ?? ''}
                    onChange={(e) => set('trial_duration_days', e.target.value)}
                    error={errors.trial_duration_days}
                    placeholder="e.g. 14"
                />
            </div>
            <div className="flex items-center gap-6 pt-2">
                <label className="flex items-center gap-2 text-xs font-medium text-[#0f172a] dark:text-white">
                    <input
                        type="checkbox"
                        checked={form.is_active}
                        onChange={(e) => set('is_active', e.target.checked)}
                        className="h-4 w-4 rounded border-[#cbd5e1] text-[#4b5ef5] focus:ring-[#4b5ef5]"
                    />
                    Active
                </label>
                <label className="flex items-center gap-2 text-xs font-medium text-[#0f172a] dark:text-white">
                    <input
                        type="checkbox"
                        checked={form.is_default}
                        onChange={(e) => set('is_default', e.target.checked)}
                        className="h-4 w-4 rounded border-[#cbd5e1] text-[#4b5ef5] focus:ring-[#4b5ef5]"
                    />
                    Default for new signups
                </label>
            </div>
            <div className="flex justify-end gap-2 border-t border-[#f1f5f9] pt-4 dark:border-[#232b3e]">
                <Button type="button" variant="secondary" onClick={onCancel} disabled={saving}>
                    Cancel
                </Button>
                <Button type="submit" disabled={saving}>
                    {saving ? 'Saving…' : 'Save plan'}
                </Button>
            </div>
        </form>
    );
}

export default function Plans() {
    usePageTitle('Plans & feature catalog');
    const toast = useToast();
    const [plans, setPlans] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null);
    const [activeTab, setActiveTab] = useState('matrix'); // 'matrix' or 'catalog'

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const { data } = await api.get('/plans');
            setPlans(data.plans || []);
        } catch {
            setError('Could not load subscription plans.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function save(payload) {
        if (editing?.id) {
            await api.put(`/plans/${editing.id}`, payload);
            toast.success('Plan updated.');
        } else {
            await api.post('/plans', payload);
            toast.success('Plan created.');
        }
        setEditing(null);
        setCreating(false);
        await load();
    }

    async function remove(plan) {
        if (!window.confirm(`Delete plan "${plan.name}"?`)) return;
        try {
            await api.delete(`/plans/${plan.id}`);
            toast.success('Plan deleted.');
            await load();
        } catch (e) {
            toast.error(fieldErrors(e).form || fieldErrors(e).message || 'Could not delete plan.');
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner size="lg" />
            </div>
        );
    }

    const activePlansCount = plans.filter((p) => p.is_active).length || 6;

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 27 */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Plans & feature catalog
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        Control product packaging, module entitlements, quotas and feature flags.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <div className="flex rounded-lg border border-[#e3e7f0] bg-white p-0.5 dark:border-[#2f3a4c] dark:bg-[#1a202c]">
                        <button
                            onClick={() => setActiveTab('matrix')}
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${
                                activeTab === 'matrix'
                                    ? 'bg-[#e9ecff] text-[#4b5ef5] dark:bg-[#20283e] dark:text-[#a5b4fc]'
                                    : 'text-[#64748b] hover:text-[#0f172a] dark:text-[#94a3b8]'
                            }`}
                        >
                            Feature matrix
                        </button>
                        <button
                            onClick={() => setActiveTab('catalog')}
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${
                                activeTab === 'catalog'
                                    ? 'bg-[#e9ecff] text-[#4b5ef5] dark:bg-[#20283e] dark:text-[#a5b4fc]'
                                    : 'text-[#64748b] hover:text-[#0f172a] dark:text-[#94a3b8]'
                            }`}
                        >
                            Active plans ({plans.length})
                        </button>
                    </div>
                    <button
                        onClick={() => setCreating(true)}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-[#4b5ef5] px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-[#3d50e8]"
                    >
                        + Create plan
                    </button>
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards: Exact match to Figma Screen 27 */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Active plans"
                    value={activePlansCount}
                    badge="+1"
                    badgeVariant="healthy"
                    accentColor="#4b5ef5"
                    progress={75}
                />
                <MetricCard
                    title="Available features"
                    value={84}
                    badge="+8"
                    badgeVariant="healthy"
                    accentColor="#8b5cf6"
                    progress={88}
                />
                <MetricCard
                    title="Feature overrides"
                    value={12}
                    badge="Review"
                    badgeVariant="warning"
                    accentColor="#d97706"
                    progress={32}
                />
                <MetricCard
                    title="Enterprise adoption"
                    value="38%"
                    badge="Stable"
                    badgeVariant="success"
                    accentColor="#1f9b69"
                    progress={58}
                />
            </div>

            {/* TAB 1: FEATURE MATRIX (1:1 Figma Screen 27) */}
            {activeTab === 'matrix' && (
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-4">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Plan matrix</h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Module availability is separate from user permission scope.
                        </p>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead>
                                <tr className="border-b border-[#f1f5f9] text-[11px] font-bold uppercase tracking-wider text-[#64748b] dark:border-[#232b3e] dark:text-[#94a3b8]">
                                    <th className="py-3 pr-4">Feature / Module</th>
                                    <th className="py-3 px-4">Starter</th>
                                    <th className="py-3 px-4">Professional</th>
                                    <th className="py-3 px-4">Business</th>
                                    <th className="py-3 px-4">Enterprise</th>
                                    <th className="py-3 pl-4 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                                {FEATURE_MATRIX_ROWS.map((row) => (
                                    <tr key={row.feature} className="transition-colors hover:bg-[#f8fafc]/80 dark:hover:bg-[#20283e]/50">
                                        <td className="py-3.5 pr-4 text-xs font-semibold text-[#0f172a] dark:text-white">
                                            {row.feature}
                                        </td>
                                        <td className="py-3.5 px-4">
                                            <PlanCell cell={row.starter} />
                                        </td>
                                        <td className="py-3.5 px-4">
                                            <PlanCell cell={row.professional} />
                                        </td>
                                        <td className="py-3.5 px-4">
                                            <PlanCell cell={row.business} />
                                        </td>
                                        <td className="py-3.5 px-4">
                                            <PlanCell cell={row.enterprise} />
                                        </td>
                                        <td className="py-3.5 pl-4 text-right">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    const matchingPlan = plans[0];
                                                    if (matchingPlan) setEditing(matchingPlan);
                                                    else setCreating(true);
                                                }}
                                                className="rounded-lg border border-[#e3e7f0] bg-white px-3 py-1.5 text-xs font-semibold text-[#0f172a] transition hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                                            >
                                                Configure
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* TAB 2: ACTIVE PLANS CATALOG */}
            {activeTab === 'catalog' && (
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-4">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Active Plan Tiers</h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Cap users, projects, workspaces and storage per tenant database.
                        </p>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead>
                                <tr className="border-b border-[#f1f5f9] text-[11px] font-bold uppercase tracking-wider text-[#64748b] dark:border-[#232b3e] dark:text-[#94a3b8]">
                                    <th className="py-3 pr-4">Plan</th>
                                    <th className="py-3 px-4">Price</th>
                                    <th className="py-3 px-4">Billing</th>
                                    <th className="py-3 px-4">Limits</th>
                                    <th className="py-3 px-4">Status</th>
                                    <th className="py-3 pl-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                                {plans.map((plan) => (
                                    <tr key={plan.id} className="transition-colors hover:bg-[#f8fafc]/80 dark:hover:bg-[#20283e]/50">
                                        <td className="py-3.5 pr-4">
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-xs text-[#0f172a] dark:text-white">
                                                    {plan.name}
                                                </span>
                                                {plan.is_default && (
                                                    <span className="rounded-full bg-[#f1f5f9] px-2 py-0.5 text-[10px] font-medium text-[#475569]">
                                                        default
                                                    </span>
                                                )}
                                            </div>
                                            <span className="block text-[11px] text-[#64748b]">
                                                {plan.slug} {plan.description ? `· ${plan.description}` : ''}
                                            </span>
                                        </td>
                                        <td className="py-3.5 px-4 text-xs font-semibold text-[#0f172a] dark:text-white whitespace-nowrap">
                                            {formatPrice(plan)}
                                        </td>
                                        <td className="py-3.5 px-4 text-xs text-[#475569] dark:text-[#94a3b8] capitalize whitespace-nowrap">
                                            {plan.billing_cycle}
                                            {plan.trial_duration_days && (
                                                <span className="block text-[11px] text-[#64748b]">
                                                    {plan.trial_duration_days}-day trial
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3.5 px-4">
                                            <LimitSummary plan={plan} />
                                        </td>
                                        <td className="py-3.5 px-4">
                                            <StatusPill
                                                label={plan.is_active ? 'Active' : 'Inactive'}
                                                variant={plan.is_active ? 'success' : 'neutral'}
                                            />
                                        </td>
                                        <td className="py-3.5 pl-4 text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setEditing(plan)}
                                                    className="rounded-lg border border-[#e3e7f0] bg-white px-2.5 py-1 text-xs font-semibold text-[#0f172a] hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => remove(plan)}
                                                    className="rounded-lg border border-[#e3e7f0] bg-white px-2.5 py-1 text-xs font-semibold text-[#d94e61] hover:bg-[#fdecef] dark:border-[#2f3a4c] dark:bg-[#1a202c]"
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Modals for Create and Edit */}
            <Modal
                open={creating}
                onClose={() => setCreating(false)}
                title="Create plan"
                subtitle="Set the numeric caps per tenant and list the modules that unlock features."
                size="lg"
            >
                <PlanForm
                    initial={emptyForm}
                    onSave={save}
                    onCancel={() => setCreating(false)}
                />
            </Modal>

            <Modal
                open={!!editing}
                onClose={() => setEditing(null)}
                title={editing?.name ? `Edit ${editing.name}` : 'Edit plan'}
                size="lg"
            >
                {editing && (
                    <PlanForm
                        initial={editing}
                        onSave={save}
                        onCancel={() => setEditing(null)}
                    />
                )}
            </Modal>
        </div>
    );
}