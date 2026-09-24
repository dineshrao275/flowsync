import { useEffect, useState } from 'react';
import api from '../services/api';
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

const MODULE_LABEL = {
    time_tracking: 'Time Tracking',
    reports: 'Reports',
    global_search: 'Global Search',
    api: 'API Access',
    branding: 'Branding',
    audit_export: 'Audit Export',
};

export default function FeatureManagement() {
    usePageTitle('Feature Management');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Features' }]);
    const { toast } = useToast();
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

    if (!data) return <Spinner />;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">Feature Management</h1>
                <p className="text-sm text-gray-500">
                    Toggle modules per plan. Modules drive the Phase 15 feature gates — a tenant on a plan
                    without a module gets 403 on those routes.
                </p>
            </div>

            <Card>
                <table className="w-full text-left text-sm">
                    <thead className="text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th className="pb-2 font-semibold">Module</th>
                            {data.plans.map((plan) => (
                                <th key={plan.id} className="pb-2 text-center font-semibold">
                                    {plan.name}
                                    {!plan.is_active && <span className="block text-[10px] text-gray-300">inactive</span>}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {data.modules.map((module) => (
                            <tr key={module} className="border-t border-gray-100">
                                <td className="py-2.5 font-medium text-gray-800">
                                    {MODULE_LABEL[module] || module}
                                    <span className="ml-2 font-mono text-xs text-gray-400">{module}</span>
                                </td>
                                {data.plans.map((plan) => {
                                    const on = isOn(plan, module);
                                    const key = `${plan.id}:${module}`;
                                    return (
                                        <td key={plan.id} className="py-2.5 text-center">
                                            <button
                                                type="button"
                                                disabled={busy === key}
                                                onClick={() => toggle(plan, module, !on)}
                                                aria-label={`${on ? 'Disable' : 'Enable'} ${MODULE_LABEL[module] || module} for ${plan.name}`}
                                                className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors ${
                                                    on ? 'bg-indigo-600' : 'bg-gray-200'
                                                }`}
                                            >
                                                <span
                                                    className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
                                                        on ? 'translate-x-6' : 'translate-x-1'
                                                    }`}
                                                />
                                            </button>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </div>
    );
}