import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { useConfirm } from '../components/ui/ConfirmDialog';

const emptyForm = { key: '', description: '' };

export default function FeatureFlags() {
    const { t } = useTranslation();
    usePageTitle(t('admin.featureFlags.pageTitle'));
    useSetCrumbs([{ label: t('admin.platform.breadcrumb'), to: '/admin' }, { label: t('admin.featureFlags.crumb') }]);
    const toast = useToast();
    const confirm = useConfirm();
    const [flags, setFlags] = useState(null);
    const [tenants, setTenants] = useState([]);
    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});
    const [overrideTenant, setOverrideTenant] = useState({});

    const load = useCallback(() => {
        api.get('/system/feature-flags').then(({ data }) => setFlags(data.flags)).catch(() => setFlags([]));
    }, []);

    useEffect(() => {
        load();
        api.get('/tenants', { params: { per_page: 100 } })
            .then(({ data }) => setTenants(data.tenants || []))
            .catch(() => {});
    }, [load]);

    const replace = (flag) => setFlags((list) => list.map((f) => (f.id === flag.id ? flag : f)));

    async function run(promise, after) {
        try {
            const { data } = await promise;
            if (data.flag) replace(data.flag);
            if (after) after(data);
            toast.success(data.message);
        } catch (e) {
            toast.error(fieldErrors(e).form || t('admin.featureFlags.requestFailed'));
        }
    }

    async function create(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post('/system/feature-flags', form);
            setForm(emptyForm);
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    if (flags === null) return <ContentSkeleton size="sm" />;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">{t('admin.featureFlags.title')}</h1>
                <p className="text-sm text-gray-500">
                    {t('admin.featureFlags.subtitle')}
                </p>
            </div>

            <Card>
                <form onSubmit={create} className="grid gap-3 md:grid-cols-3">
                    <Input label={t('admin.featureFlags.key')} value={form.key} error={errors.key} placeholder="new_board"
                        onChange={(e) => setForm({ ...form, key: e.target.value })} />
                    <Input label={t('admin.featureFlags.description')} value={form.description} error={errors.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })} />
                    <div className="flex items-end"><Button type="submit">{t('admin.featureFlags.addFlag')}</Button></div>
                </form>
            </Card>

            {flags.length === 0 && <p className="text-sm text-gray-400">{t('admin.featureFlags.empty')}</p>}

            {flags.map((flag) => (
                <Card key={flag.id}>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="font-mono text-sm font-semibold text-gray-800">{flag.key}</p>
                            <p className="text-xs text-gray-500">{flag.description || t('admin.featureFlags.noDescription')}</p>
                        </div>
                        <div className="flex items-center gap-3">
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" checked={flag.enabled}
                                    onChange={(e) => run(api.put(`/system/feature-flags/${flag.id}`, { enabled: e.target.checked }))} />
                                {t('admin.featureFlags.enabled')}
                            </label>
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                {t('admin.featureFlags.rollout')}
                                <input type="number" min="0" max="100" defaultValue={flag.rollout_percent}
                                    className="w-16 rounded-lg border border-gray-200 px-2 py-1 text-sm"
                                    onBlur={(e) => Number(e.target.value) !== flag.rollout_percent
                                        && run(api.put(`/system/feature-flags/${flag.id}`, { rollout_percent: Number(e.target.value) }))} />
                                %
                            </label>
                            <Button
                                variant="danger"
                                onClick={async () => {
                                    if (!(await confirm({
                                        title: t('admin.featureFlags.deleteTitle'),
                                        message: t('admin.featureFlags.deleteMessage', { key: flag.key }),
                                        variant: 'danger',
                                    }))) return;
                                    run(api.delete(`/system/feature-flags/${flag.id}`), load);
                                }}
                            >
                                {t('admin.common.delete')}
                            </Button>
                        </div>
                    </div>

                    <div className="mt-4 border-t border-gray-100 pt-3">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{t('admin.featureFlags.tenantOverrides')}</p>
                        <div className="flex flex-wrap gap-2">
                            {flag.overrides.map((o) => (
                                <Badge key={o.tenant_id}>
                                    {o.tenant?.name || o.tenant_id}: {o.enabled ? t('admin.featureFlags.on') : t('admin.featureFlags.off')}
                                    <button
                                        type="button"
                                        className="ml-2 text-gray-400 hover:text-red-600"
                                        aria-label={t('admin.featureFlags.removeOverrideAria')}
                                        onClick={async () => {
                                            if (!(await confirm({
                                                title: t('admin.featureFlags.removeOverrideTitle'),
                                                message: t('admin.featureFlags.removeOverrideMessage', { tenant: o.tenant?.name || t('admin.featureFlags.tenantFallback', { id: o.tenant_id }) }),
                                                variant: 'danger',
                                            }))) return;
                                            run(api.delete(`/system/feature-flags/${flag.id}/overrides/${o.tenant_id}`));
                                        }}
                                    >
                                        x
                                    </button>
                                </Badge>
                            ))}
                        </div>
                        <div className="mt-2 flex gap-2">
                            <select className="rounded-lg border border-gray-200 px-2 py-1 text-sm"
                                value={overrideTenant[flag.id] || ''}
                                onChange={(e) => setOverrideTenant({ ...overrideTenant, [flag.id]: e.target.value })}>
                                <option value="">{t('admin.featureFlags.selectTenant')}</option>
                                {tenants.map((tenant) => <option key={tenant.id} value={tenant.id}>{tenant.name}</option>)}
                            </select>
                            {[true, false].map((on) => (
                                <Button key={String(on)} variant="secondary" disabled={!overrideTenant[flag.id]}
                                    onClick={() => run(api.put(`/system/feature-flags/${flag.id}/overrides/${overrideTenant[flag.id]}`, { enabled: on }))}>
                                    {on ? t('admin.featureFlags.forceOn') : t('admin.featureFlags.forceOff')}
                                </Button>
                            ))}
                        </div>
                    </div>
                </Card>
            ))}
        </div>
    );
}
