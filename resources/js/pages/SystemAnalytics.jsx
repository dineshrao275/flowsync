import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

const STATUS_COLOR = {
    active: '#16a34a',
    trial: '#f59e0b',
    suspended: '#ef4444',
    pending: '#94a3b8',
    expired: '#64748b',
    deactivated: '#64748b',
    provisioning_failed: '#0ea5e9',
};

function Bar({ label, count, max, color }) {
    return (
        <li className="text-sm">
            <div className="flex items-center justify-between gap-3">
                <span className="truncate font-medium text-gray-700 dark:text-[#CBD5E1]">{label}</span>
                <span className="shrink-0 font-semibold text-gray-900 dark:text-[#F3F4F6]">{count}</span>
            </div>
            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-[#2F3A4C]">
                <div className="h-full rounded-full transition-all duration-300" style={{ width: `${Math.max(2, (count / max) * 100)}%`, backgroundColor: color }} />
            </div>
        </li>
    );
}

export default function SystemAnalytics() {
    usePageTitle('Platform Analytics');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Analytics' }]);
    const [data, setData] = useState(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        api.get('/system/analytics')
            .then(({ data }) => setData(data))
            .catch(() => setError(true));
    }, []);

    if (error) return <p className="text-sm text-gray-500 dark:text-[#94A3B8]">Analytics are currently unavailable.</p>;
    if (!data) return <Spinner />;

    const tenantMax = Math.max(...data.tenants.by_status.map((s) => s.count), 1);
    const subMax = Math.max(...data.subscriptions.by_status.map((s) => s.count), 1);

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight text-gray-900 dark:text-[#F3F4F6]">Platform Analytics</h1>
                <p className="mt-1 text-sm text-gray-500 dark:text-[#94A3B8]">Fleet-wide tenant and subscription resource metrics.</p>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title="Resources" subtitle="Across serviceable tenants (cached 5m)">
                    <div className="grid grid-cols-2 gap-3">
                        {[
                            ['Users', data.resources.users],
                            ['Workspaces', data.resources.workspaces],
                            ['Projects', data.resources.projects],
                            ['Tasks', data.resources.tasks],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-xl border border-gray-100 dark:border-[#2F3A4C] bg-gray-50/70 dark:bg-[#161B26] p-3 text-center">
                                <p className="text-xl font-bold text-gray-900 dark:text-[#F3F4F6]">{value}</p>
                                <p className="text-xs text-gray-500 dark:text-[#94A3B8]">{label}</p>
                            </div>
                        ))}
                    </div>

                    <div className="mt-5">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-[#64748B]">Per tenant</p>
                        <ul className="space-y-2">
                            {data.tenants.by_status.map((s) => (
                                <Bar key={s.status} label={s.status} count={s.count} max={tenantMax} color={STATUS_COLOR[s.status] || '#94a3b8'} />
                            ))}
                            {data.tenants.by_status.length === 0 && <li className="text-sm text-gray-400 dark:text-[#64748B]">No tenants.</li>}
                        </ul>
                    </div>
                </Card>

                <Card title="Subscriptions" subtitle="Central subscription rows by status">
                    <ul className="space-y-2">
                        {data.subscriptions.by_status.map((s) => (
                            <Bar key={s.status} label={s.status} count={s.count} max={subMax} color="#f97316" />
                        ))}
                        {data.subscriptions.by_status.length === 0 && <li className="text-sm text-gray-400 dark:text-[#64748B]">No subscriptions yet.</li>}
                    </ul>

                    <div className="mt-5">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-[#64748B]">Flags</p>
                        <ul className="space-y-1 text-sm text-gray-700 dark:text-[#CBD5E1]">
                            <li className="flex justify-between">Provisioning failed <span className="font-semibold text-gray-900 dark:text-[#F3F4F6]">{data.tenants.provisioning_failed}</span></li>
                            <li className="flex justify-between">In trash (deleted) <span className="font-semibold text-gray-900 dark:text-[#F3F4F6]">{data.tenants.trashed}</span></li>
                        </ul>
                    </div>
                </Card>
            </div>

            <Card title="Largest tenants" subtitle="By tracked users">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-gray-100 dark:border-[#2F3A4C] text-xs uppercase tracking-wider text-gray-400 dark:text-[#64748B]">
                        <tr>
                            <th className="pb-2 font-semibold">Tenant</th>
                            <th className="pb-2 font-semibold">Status</th>
                            <th className="pb-2 text-right font-semibold">Users</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-[#2F3A4C]">
                        {data.top_tenants.map((t) => (
                            <tr key={t.id} className="transition-colors hover:bg-gray-50/50 dark:hover:bg-[#1C2433]">
                                <td className="py-2.5">
                                    <Link to={`/tenants/${t.id}`} className="font-medium text-[var(--accent)] hover:opacity-80">
                                        {t.tenant.name}
                                    </Link>
                                    <span className="ml-2 font-mono text-xs text-gray-400 dark:text-[#64748B]">{t.tenant.slug}</span>
                                </td>
                                <td className="py-2.5"><Badge>{t.tenant.status}</Badge></td>
                                <td className="py-2.5 text-right font-semibold text-gray-900 dark:text-[#F3F4F6]">{t.users}</td>
                            </tr>
                        ))}
                        {data.top_tenants.length === 0 && (
                            <tr><td colSpan={3} className="py-4 text-center text-gray-400 dark:text-[#64748B]">No serviceable tenants.</td></tr>
                        )}
                    </tbody>
                </table>
            </Card>
        </div>
    );
}