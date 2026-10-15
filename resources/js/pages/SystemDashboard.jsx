import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

function Stat({ label, value, hint }) {
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p className="text-2xl font-bold text-gray-800">{value}</p>
            <p className="text-xs font-medium text-gray-500">{label}</p>
            {hint && <p className="mt-1 text-xs text-gray-400">{hint}</p>}
        </div>
    );
}

function Bar({ label, count, max, color }) {
    return (
        <li className="text-sm">
            <div className="flex items-center justify-between gap-3">
                <span className="truncate font-medium text-gray-700">{label}</span>
                <span className="shrink-0 font-semibold text-gray-900">{count}</span>
            </div>
            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100">
                <div className="h-full rounded-full" style={{ width: `${Math.max(2, (count / max) * 100)}%`, backgroundColor: color }} />
            </div>
        </li>
    );
}

const STATUS_COLOR = {
    active: '#16a34a',
    trial: '#f59e0b',
    suspended: '#ef4444',
    pending: '#94a3b8',
    expired: '#64748b',
    deactivated: '#64748b',
    provisioning_failed: '#0ea5e9',
};

/**
 * Platform overview: health and activity across every tenant, from the one
 * `/system/analytics` payload. This used to be two pages (Overview tiles +
 * an Analytics page re-rendering the same data as bars); they are merged
 * here so the numbers cannot disagree and there is one place to extend.
 */
export default function SystemDashboard() {
    const { t } = useTranslation();
    usePageTitle(t('admin.systemDashboard.pageTitle'));
    useSetCrumbs([{ label: t('admin.platform.breadcrumb') }]);
    const [data, setData] = useState(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        api.get('/system/analytics')
            .then(({ data }) => setData(data))
            .catch(() => setError(true));
    }, []);

    if (error) return <p className="text-sm text-gray-400">{t('admin.systemDashboard.unavailable')}</p>;
    if (!data) return <Spinner />;

    const statusLabel = (status) => t(`admin.systemDashboard.statuses.${status}`, { defaultValue: status });
    const statusCount = (status) => data.tenants.by_status.find((s) => s.status === status)?.count || 0;
    const tenantMax = Math.max(...data.tenants.by_status.map((s) => s.count), 1);

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">{t('admin.systemDashboard.heading')}</h1>
                <p className="text-sm text-gray-500">{t('admin.systemDashboard.subtitle')}</p>
            </div>

            <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <Stat label={t('admin.systemDashboard.statTenants')} value={data.tenants.total} />
                <Stat label={t('admin.systemDashboard.statActive')} value={statusCount('active')} />
                <Stat label={t('admin.systemDashboard.statTrialing')} value={statusCount('trial')} />
                <Stat label={t('admin.systemDashboard.statSuspended')} value={statusCount('suspended')} />
                <Stat label={t('admin.systemDashboard.statProvisioningFailed')} value={data.tenants.provisioning_failed} />
                <Stat label={t('admin.systemDashboard.statDeleted')} value={data.tenants.trashed} />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title={t('admin.systemDashboard.resources')} subtitle={t('admin.systemDashboard.resourcesSubtitle')}>
                    <div className="grid grid-cols-2 gap-3">
                        <Stat label={t('admin.systemDashboard.statUsers')} value={data.resources.users} />
                        <Stat label={t('admin.systemDashboard.statWorkspaces')} value={data.resources.workspaces} />
                        <Stat label={t('admin.systemDashboard.statProjects')} value={data.resources.projects} />
                        <Stat label={t('admin.systemDashboard.statTasks')} value={data.resources.tasks} />
                    </div>
                </Card>

                <Card title={t('admin.systemDashboard.tenantsByStatus')} subtitle={t('admin.systemDashboard.tenantsByStatusSubtitle')}>
                    <ul className="space-y-2">
                        {data.tenants.by_status.map((s) => (
                            <Bar key={s.status} label={statusLabel(s.status)} count={s.count} max={tenantMax} color={STATUS_COLOR[s.status] || '#94a3b8'} />
                        ))}
                        {data.tenants.by_status.length === 0 && <li className="text-sm text-gray-400">{t('admin.systemDashboard.noTenants')}</li>}
                    </ul>
                </Card>

                <Card title={t('admin.systemDashboard.subscriptions')} subtitle={t('admin.systemDashboard.subscriptionsSubtitle')}>
                    <div className="space-y-2">
                        {data.subscriptions.by_status.length === 0 && (
                            <p className="text-sm text-gray-400">{t('admin.systemDashboard.noSubscriptions')}</p>
                        )}
                        {data.subscriptions.by_status.map((s) => (
                            <div key={s.status} className="flex items-center justify-between text-sm">
                                <span className="capitalize text-gray-600">{s.status}</span>
                                <span className="font-semibold text-gray-800">{s.count}</span>
                            </div>
                        ))}
                        {data.subscriptions.by_status.length > 0 && (
                            <div className="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 text-sm font-medium">
                                <span className="text-gray-500">{t('admin.systemDashboard.total')}</span>
                                <span className="font-bold text-gray-800">{data.subscriptions.total}</span>
                            </div>
                        )}
                    </div>
                </Card>
            </div>

            <Card title={t('admin.systemDashboard.largestTenants')} subtitle={t('admin.systemDashboard.largestTenantsSubtitle')}>
                <table className="w-full text-left text-sm">
                    <thead className="text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th scope="col" className="pb-2 font-semibold">{t('admin.systemDashboard.colTenant')}</th>
                            <th scope="col" className="pb-2 font-semibold">{t('admin.systemDashboard.colStatus')}</th>
                            <th scope="col" className="pb-2 text-right font-semibold">{t('admin.systemDashboard.colUsers')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.top_tenants.map((tenant) => (
                            <tr key={tenant.id} className="border-t border-gray-100">
                                <td className="py-2">
                                    <Link to={`/tenants/${tenant.id}`} className="font-medium text-[var(--accent-soft-text)] hover:text-[var(--accent)]">
                                        {tenant.tenant.name}
                                    </Link>
                                    <span className="ml-2 text-xs text-gray-400">{tenant.tenant.slug}</span>
                                </td>
                                <td className="py-2"><Badge>{statusLabel(tenant.tenant.status)}</Badge></td>
                                <td className="py-2 text-right font-semibold text-gray-800">{tenant.users}</td>
                            </tr>
                        ))}
                        {data.top_tenants.length === 0 && (
                            <tr><td colSpan={3} className="py-4 text-gray-400">{t('admin.systemDashboard.noServiceable')}</td></tr>
                        )}
                    </tbody>
                </table>
            </Card>
        </div>
    );
}
