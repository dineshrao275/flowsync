import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import { useToast } from '../context/ToastContext';
import { formatPrice } from '../utils/format';
import usePageTitle from '../hooks/usePageTitle';

const MODULES = ['time_tracking', 'reports', 'global_search', 'api', 'branding', 'audit_export'];

const emptyForm = {
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

function PlanForm({ initial, onSave, onCancel }) {
    const [form, setForm] = useState(initial);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const modules = form.limits?.modules || [];

    function set(field, value) {
        setForm((f) => ({ ...f, [field]: value }));
    }

    function toggleModule(module) {
        const next = modules.includes(module) ? modules.filter((m) => m !== module) : [...modules, module];
        set('limits', { ...(form.limits || {}), modules: next });
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
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Input label="Price (cents)" type="number" name="price_cents" value={form.price_cents} onChange={(e) => set('price_cents', Number(e.target.value))} error={errors.price_cents} />
                <Input label="Currency" name="currency" value={form.currency} onChange={(e) => set('currency', e.target.value.toUpperCase())} maxLength={3} />
                <Input label="Trial (days)" type="number" name="trial_duration_days" value={form.trial_duration_days} onChange={(e) => set('trial_duration_days', e.target.value)} error={errors.trial_duration_days} placeholder="None" />
                <Input label="Sort order" type="number" name="sort_order" value={form.sort_order} onChange={(e) => set('sort_order', Number(e.target.value))} />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <label className="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" checked={form.billing_cycle === 'annual'} onChange={(e) => set('billing_cycle', e.target.checked ? 'annual' : 'monthly')} />
                    Annual billing ({form.billing_cycle === 'annual' ? '12-month periods' : 'monthly periods'})
                </label>
                <label className="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
                    Active
                </label>
                {!initial.id && (
                    <label className="col-span-2 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={form.is_default} onChange={(e) => set('is_default', e.target.checked)} />
                        Default plan (auto-assigned at onboarding)
                    </label>
                )}
            </div>
            <div>
                <p className="mb-2 text-sm font-medium text-gray-700">Included modules</p>
                <div className="grid grid-cols-2 gap-2">
                    {MODULES.map((module) => (
                        <label key={module} className="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                            <input type="checkbox" checked={modules.includes(module)} onChange={() => toggleModule(module)} />
                            {module}
                        </label>
                    ))}
                </div>
            </div>
            {errors.form && <Alert tone="error">{errors.form}</Alert>}
            <div className="flex justify-end gap-2 pt-1">
                <Button type="button" variant="secondary" onClick={onCancel}>
                    Cancel
                </Button>
                <Button type="submit" loading={saving}>
                    {initial.id ? 'Save plan' : 'Create plan'}
                </Button>
            </div>
        </form>
    );
}

export default function Plans() {
    usePageTitle('Plans');
    const toast = useToast();
    const [plans, setPlans] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(null);
    const [creating, setCreating] = useState(false);

    const load = useCallback(async () => {
        try {
            const { data } = await api.get('/plans');
            setPlans(data.plans);
        } catch {
            setError('Unable to load plans.');
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
                <Spinner />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">Subscription plans</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Plan limits and feature modules drive tenant quotas and entitlements.
                    </p>
                </div>
                <Button size="md" onClick={() => setCreating((open) => !open)}>
                    New plan
                </Button>
            </div>

            {error && <Alert>{error}</Alert>}

            <Modal
                open={creating}
                onClose={() => setCreating(false)}
                title="Create plan"
                subtitle="Machines-read limits: max out numeric caps, list modules that unlock features."
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

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {plans.map((plan, index) => (
                    <div key={plan.id} className="animate-fade-in-up" style={{ animationDelay: `${index * 60}ms` }}>
                        <Card
                            className="h-full"
                            title={
                                <span className="flex items-center gap-2">
                                    {plan.name}
                                    {plan.is_default && <Badge>default</Badge>}
                                </span>
                            }
                            subtitle={plan.description || 'No description'}
                            actions={
                                <span className="flex items-center gap-2">
                                    <Badge>{plan.is_active ? 'active' : 'inactive'}</Badge>
                                    <Badge>{plan.billing_cycle}</Badge>
                                </span>
                            }
                        >
                        <p className="text-sm font-semibold text-gray-900">{formatPrice(plan)}</p>
                        {plan.trial_duration_days && (
                            <p className="mt-0.5 text-xs text-gray-500">{plan.trial_duration_days}-day trial</p>
                        )}
                        <div className="mt-4 space-y-1.5 text-sm text-gray-600">
                            <p><span className="font-medium text-gray-900">{plan.limits?.users ?? '∞'}</span> users</p>
                            <p><span className="font-medium text-gray-900">{plan.limits?.projects ?? '∞'}</span> projects</p>
                            <p><span className="font-medium text-gray-900">{plan.limits?.tasks ?? '∞'}</span> tasks</p>
                            <p><span className="font-medium text-gray-900">{(plan.limits?.modules || []).length}</span> modules</p>
                        </div>
                        <div className="mt-4 flex items-center gap-3 border-t border-gray-100 pt-3">
                            <button type="button" className="text-sm font-medium text-indigo-600 hover:text-indigo-800" onClick={() => setEditing(plan)}>
                                Edit
                            </button>
                            <button type="button" className="text-sm font-medium text-red-500 hover:text-red-700" onClick={() => remove(plan)}>
                                Delete
                            </button>
                        </div>
                        </Card>
                    </div>
                ))}
            </div>
        </div>
    );
}