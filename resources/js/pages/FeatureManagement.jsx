import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Card from '../components/ui/Card';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import ErrorState from '../components/ui/ErrorState';
import { useResource } from '../hooks/useResource';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

export default function FeatureManagement() {
    const { t } = useTranslation();
    usePageTitle(t('admin.featureManagement.pageTitle'));
    useSetCrumbs([{ label: t('admin.platform.breadcrumb'), to: '/admin' }, { label: t('admin.featureManagement.pageTitle') }]);
    const toast = useToast();
    const { data, setData, error, reload } = useResource((signal) => api.get('/system/features', { signal }).then(({ data: res }) => res), []);
    const [busy, setBusy] = useState(null);

    function isOn(plan, module) {
        return (plan.modules || []).includes(module);
    }

    async function toggle(plan, module, enabled) {
        const key = `${plan.id}:${module}`;
        setBusy(key);
        try {
            const { data: res } = await api.put(`/system/features/${plan.id}`, { module, enabled });
            toast.success(res.message);
            setData((d) => ({
                ...d,
                plans: d.plans.map((p) => (p.id === plan.id ? { ...p, modules: res.plan.modules } : p)),
            }));
        } catch (e) {
            toast.error(t('admin.featureManagement.updateFailed'));
        } finally {
            setBusy(null);
        }
    }

    function Toggle({ plan, module, label }) {
        const on = isOn(plan, module);
        const key = `${plan.id}:${module}`;
        return (
            <button
                type="button"
                disabled={busy === key}
                onClick={() => toggle(plan, module, !on)}
                aria-label={t('admin.featureManagement.toggleLabel', {
                    action: on ? t('admin.featureManagement.disable') : t('admin.featureManagement.enable'),
                    module: label,
                    plan: plan.name,
                })}
                className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors ${
                    on ? 'bg-[var(--accent)]' : 'bg-gray-200'
                }`}
            >
                <span
                    className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
                        on ? 'translate-x-6' : 'translate-x-1'
                    }`}
                />
            </button>
        );
    }

    if (!data) return error ? <ErrorState onRetry={reload} /> : <ContentSkeleton size="sm" />;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">{t('admin.featureManagement.heading')}</h1>
                <p className="text-sm text-gray-500">
                    {t('admin.featureManagement.subtitle')}
                </p>
            </div>

            {data.groups.map((group) => (
                <Card key={group.key}>
                    <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400">
                        {group.label}
                    </h2>
                    <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th scope="col" className="pb-2 font-semibold" />
                                {data.plans.map((plan) => (
                                    <th scope="col" key={plan.id} className="pb-2 text-center font-semibold">
                                        {plan.name}
                                        {!plan.is_active && (
                                            <span className="block text-xs text-gray-300">{t('admin.featureManagement.inactive')}</span>
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {group.modules.map((module) => (
                                <tr key={module.key} className="border-t border-gray-100">
                                    <td
                                        className="py-2.5 font-medium text-gray-800"
                                        style={{ paddingLeft: `${(module.depth - 1) * 1.25}rem` }}
                                    >
                                        <span
                                            className={module.depth > 1 ? 'text-gray-600' : undefined}
                                        >
                                            {module.label}
                                        </span>
                                        <span className="ml-2 font-mono text-xs text-gray-400">
                                            {module.key}
                                        </span>
                                    </td>
                                    {data.plans.map((plan) => (
                                        <td key={plan.id} className="py-2.5 text-center">
                                            <Toggle plan={plan} module={module.key} label={module.label} />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                </Card>
            ))}
        </div>
    );
}
