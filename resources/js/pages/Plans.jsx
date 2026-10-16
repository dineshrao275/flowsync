import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import LimitsEditor, { limitValue, formatBytes } from '../components/billing/LimitsEditor';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import { formatPrice } from '../utils/format';
import usePageTitle from '../hooks/usePageTitle';

const MODULES = ['time_tracking', 'reports', 'global_search', 'branding'];

const MODULE_KEYS = { 'export.full': 'exportFull' };

function moduleLabel(t, m) {
    return t(`admin.subscription.modules.${MODULE_KEYS[m] || m}`, { defaultValue: m });
}

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

function LimitSummary({ plan }) {
    const { t } = useTranslation();
    const cells = [
        [t('admin.plans.limitUsers'), limitValue(plan.limits, 'users')],
        [t('admin.plans.limitWorkspaces'), limitValue(plan.limits, 'workspaces')],
        [t('admin.plans.limitProjects'), limitValue(plan.limits, 'projects')],
        [t('admin.plans.limitTasks'), limitValue(plan.limits, 'tasks')],
    ];

    return (
        <span className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-500">
            {cells.map(([label, value]) => (
                <span key={label}>
                    <span className="tabular-nums font-semibold text-gray-800">{value === null ? '∞' : value}</span> {label}
                </span>
            ))}
            <span>
                <span className="font-semibold text-gray-800">{formatBytes(limitValue(plan.limits, 'storage_bytes'))}</span> {t('admin.plans.storage')}
            </span>
        </span>
    );
}

function PlanForm({ initial, onSave, onCancel }) {
    const { t } = useTranslation();
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
                <Input label={t('admin.common.name')} name="name" value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name} required />
                <Input label={t('admin.roles.slug')} name="slug" value={form.slug} onChange={(e) => set('slug', e.target.value)} error={errors.slug} required />
            </div>
            <Input
                label={t('admin.plans.descriptionLabel')}
                name="description"
                value={form.description}
                onChange={(e) => set('description', e.target.value)}
                error={errors.description}
                placeholder={t('admin.plans.descriptionPlaceholder')}
            />
            <LimitsEditor
                key={initial.id ?? 'new'}
                limits={form.limits}
                errors={errors}
                legend={t('admin.plans.limitsLegend')}
                hint={t('admin.plans.limitsHint')}
                onChange={(next) => set('limits', next ? { ...(form.limits || {}), ...next } : { modules: [] })}
            />
            <label className="block text-sm font-medium text-gray-700">
                {t('admin.plans.productLabel')}
                <select
                    value={form.product || 'suite'}
                    disabled={Boolean(initial.id)}
                    onChange={(e) => set('product', e.target.value)}
                    className="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50"
                >
                    <option value="suite">{t('admin.plans.productSuite')}</option>
                    <option value="tms">{t('admin.plans.productTms')}</option>
                    <option value="hrms">{t('admin.plans.productHrms')}</option>
                </select>
                {initial.id && <span className="mt-1 block text-xs text-gray-400">{t('admin.plans.productFixed')}</span>}
            </label>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Input label={t('admin.plans.priceCents')} type="number" name="price_cents" value={form.price_cents} onChange={(e) => set('price_cents', Number(e.target.value))} error={errors.price_cents} />
                <Input label={t('admin.plans.currency')} name="currency" value={form.currency} onChange={(e) => set('currency', e.target.value.toUpperCase())} maxLength={3} />
                <Input label={t('admin.plans.trialDaysLabel')} type="number" name="trial_duration_days" value={form.trial_duration_days} onChange={(e) => set('trial_duration_days', e.target.value)} error={errors.trial_duration_days} placeholder={t('admin.common.none')} />
                <Input label={t('admin.plans.sortOrder')} type="number" name="sort_order" value={form.sort_order} onChange={(e) => set('sort_order', Number(e.target.value))} />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <label className="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" checked={form.billing_cycle === 'annual'} onChange={(e) => set('billing_cycle', e.target.checked ? 'annual' : 'monthly')} />
                    {t('admin.plans.annualBilling')} ({form.billing_cycle === 'annual' ? t('admin.plans.periodsAnnual') : t('admin.plans.periodsMonthly')})
                </label>
                <label className="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
                    {t('admin.plans.activeLabel')}
                </label>
                {!initial.id && (
                    <label className="col-span-2 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={form.is_default} onChange={(e) => set('is_default', e.target.checked)} />
                        {t('admin.plans.defaultPlanLabel')}
                    </label>
                )}
            </div>
            <div>
                <p className="mb-2 text-sm font-medium text-gray-700">{t('admin.plans.includedModulesHeading')}</p>
                <div className="grid grid-cols-2 gap-2">
                    {MODULES.map((module) => (
                        <label key={module} className="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                            <input type="checkbox" checked={modules.includes(module)} onChange={() => toggleModule(module)} />
                            {moduleLabel(t, module)}
                        </label>
                    ))}
                </div>
            </div>
            {errors.form && <Alert tone="error">{errors.form}</Alert>}
            <div className="flex justify-end gap-2 pt-1">
                <Button type="button" variant="secondary" onClick={onCancel}>
                    {t('admin.common.cancel')}
                </Button>
                <Button type="submit" loading={saving}>
                    {initial.id ? t('admin.plans.savePlan') : t('admin.plans.createPlan')}
                </Button>
            </div>
        </form>
    );
}

export default function Plans() {
    const { t } = useTranslation();
    usePageTitle(t('admin.plans.pageTitle'));
    const toast = useToast();
    const confirm = useConfirm();
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
            setError(t('admin.plans.loadFailed'));
        } finally {
            setLoading(false);
        }
    }, [t]);

    useEffect(() => {
        load();
    }, [load]);

    async function save(payload) {
        if (editing?.id) {
            await api.put(`/plans/${editing.id}`, payload);
            toast.success(t('admin.plans.planUpdated'));
        } else {
            await api.post('/plans', payload);
            toast.success(t('admin.plans.planCreated'));
        }
        setEditing(null);
        setCreating(false);
        await load();
    }

    async function remove(plan) {
        if (!(await confirm({ title: t('admin.plans.deleteTitle'), message: t('admin.plans.deleteMessage', { name: plan.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`/plans/${plan.id}`);
            toast.success(t('admin.plans.planDeleted'));
            await load();
        } catch (e) {
            toast.error(fieldErrors(e).form || fieldErrors(e).message || t('admin.plans.deleteFailed'));
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
                    <h2 className="text-2xl font-bold text-gray-900">{t('admin.plans.heading')}</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        {t('admin.plans.subtitle')}
                    </p>
                </div>
                <Button size="md" onClick={() => setCreating((open) => !open)}>
                    {t('admin.plans.newPlan')}
                </Button>
            </div>

            {error && <Alert>{error}</Alert>}

            <Modal
                open={creating}
                onClose={() => setCreating(false)}
                title={t('admin.plans.createTitle')}
                subtitle={t('admin.plans.createSubtitle')}
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
                title={editing?.name ? t('admin.plans.editTitleName', { name: editing.name }) : t('admin.plans.editTitle')}
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

            <Table>
                <thead>
                    <tr>
                        <Th>{t('admin.plans.thPlan')}</Th>
                        <Th>{t('admin.plans.thPrice')}</Th>
                        <Th>{t('admin.plans.thBilling')}</Th>
                        <Th>{t('admin.plans.thLimits')}</Th>
                        <Th>{t('admin.plans.thModules')}</Th>
                        <Th>{t('admin.plans.thStatus')}</Th>
                        <Th align="right">{t('admin.common.actions')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {plans.length === 0 && <TableEmpty colSpan={7}>{t('admin.plans.empty')}</TableEmpty>}
                    {plans.map((plan) => (
                        <tr key={plan.id} className="hover:bg-gray-50/60">
                            <Td>
                                <span className="font-medium text-gray-900">{plan.name}</span>
                                {plan.is_default && <span className="ml-2"><Badge>{t('admin.plans.defaultBadge')}</Badge></span>}
                                {plan.product && plan.product !== 'suite' && <span className="ml-2"><Badge>{plan.product.toUpperCase()}</Badge></span>}
                                <span className="mt-0.5 block text-xs text-gray-500">
                                    {plan.slug}
                                    {plan.description ? ` · ${plan.description}` : ''}
                                </span>
                            </Td>
                            <Td className="whitespace-nowrap font-medium text-gray-900">{formatPrice(plan)}</Td>
                            <Td className="whitespace-nowrap">
                                {plan.billing_cycle}
                                {plan.trial_duration_days ? (
                                    <span className="mt-0.5 block text-xs text-gray-500">{t('admin.plans.trialDays', { count: plan.trial_duration_days })}</span>
                                ) : null}
                            </Td>
                            <Td>
                                <LimitSummary plan={plan} />
                            </Td>
                            <Td className="tabular-nums">{(plan.limits?.modules || []).length}</Td>
                            <Td>
                                <Badge>{plan.is_active ? t('admin.plans.activeBadge') : t('admin.plans.inactiveBadge')}</Badge>
                            </Td>
                            <Td align="right" className="whitespace-nowrap">
                                <button type="button" className="text-sm font-medium text-[var(--accent-soft-text)] hover:text-[var(--accent)]" onClick={() => setEditing(plan)}>
                                    {t('admin.common.edit')}
                                </button>
                                <button type="button" className="ml-3 text-sm font-medium text-red-500 hover:text-red-700" onClick={() => remove(plan)}>
                                    {t('admin.common.delete')}
                                </button>
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </Table>
        </div>
    );
}