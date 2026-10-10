import { useEffect, useState } from 'react';
import api from '../services/api';
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

export default function FeatureManagement() {
    usePageTitle('Feature Management');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Features' }]);
    const toast = useToast();
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(null);

    useEffect(() => {
        api.get('/system/features').then(({ data }) => setData(data)).catch(() => {});
    }, []);

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
            toast.error('Failed to update the feature.');
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
                aria-label={`${on ? 'Disable' : 'Enable'} ${label} for ${plan.name}`}
                className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors ${
                    on ? 'bg-[var(--accent)]' : 'bg-gray-200 dark:bg-[#2F3A4C]'
                }`}
            >
                <span
                    className={`inline-block h-4 w-4 transform rounded-full bg-white dark:bg-[#F3F4F6] shadow-sm transition-transform ${
                        on ? 'translate-x-6' : 'translate-x-1'
                    }`}
                />
            </button>
        );
    }

    if (!data) return <Spinner />;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight text-gray-900 dark:text-[#F3F4F6]">Feature Management</h1>
                <p className="mt-1 text-sm text-gray-500 dark:text-[#94A3B8]">
                    Toggle modules per plan. Modules drive the Phase 15 feature gates — a tenant on a plan
                    without a module gets 403 on those routes. A super admin can also grant a single tenant
                    an extra module from its detail page.
                </p>
            </div>

            {data.groups.map((group) => (
                <Card key={group.key}>
                    <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-[#64748B]">
                        {group.label}
                    </h2>
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-gray-100 dark:border-[#2F3A4C]">
                            <tr>
                                <th className="pb-2 font-semibold" />
                                {data.plans.map((plan) => (
                                    <th key={plan.id} className="pb-2 text-center font-semibold text-gray-900 dark:text-[#F3F4F6]">
                                        {plan.name}
                                        {!plan.is_active && (
                                            <span className="block text-[10px] text-gray-400 dark:text-[#64748B]">inactive</span>
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-[#2F3A4C]">
                            {group.modules.map((module) => (
                                <tr key={module.key} className="transition-colors hover:bg-gray-50/50 dark:hover:bg-[#1C2433]">
                                    <td
                                        className="py-2.5 font-medium text-gray-900 dark:text-[#F3F4F6]"
                                        style={{ paddingLeft: `${(module.depth - 1) * 1.25}rem` }}
                                    >
                                        <span
                                            className={module.depth > 1 ? 'text-gray-600 dark:text-[#94A3B8]' : undefined}
                                        >
                                            {module.label}
                                        </span>
                                        <span className="ml-2 font-mono text-xs text-gray-400 dark:text-[#64748B]">
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
                    </table>
                </Card>
            ))}
        </div>
    );
}
