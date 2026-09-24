import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
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

const STATUS_LABEL = {
    trial: 'Trial',
    active: 'Active',
    suspended: 'Suspended',
    pending: 'Pending',
    expired: 'Expired',
    deactivated: 'Deactivated',
    provisioning_failed: 'Failed',
};

export default function SystemDashboard() {
    usePageTitle('Platform Overview');
    useSetCrumbs([{ label: 'Platform' }]);
    const [data, setData] = useState(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        api.get('/system/analytics')
            .then(({ data }) => setData(data))
            .catch(() => setError(true));
    }, []);

    if (error) return <p className="text-sm text-gray-400">Analytics are currently unavailable.</p>;
    if (!data) return <Spinner />;

    const statusCount = (status) => data.tenants.by_status.find((s) => s.status === status)?.count || 0;

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-800">Platform Overview</h1>
                    <p className="text-sm text-gray-500">Health and activity across every tenant.</p>
                </div>
                <Link to="/admin/analytics" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                    Full analytics →
                </Link>
            </div>

            <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <Stat label="Tenants" value={data.tenants.total} />
                <Stat label="Active" value={statusCount('active')} />
                <Stat label="Trialing" value={statusCount('trial')} />
                <Stat label="Suspended" value={statusCount('suspended')} />
                <Stat label="Provisioning failed" value={data.tenants.provisioning_failed} />
                <Stat label="Deleted (trash)" value={data.tenants.trashed} />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title="Resources" subtitle="Summed across serviceable tenants (cached 5m)">
                    <div className="grid grid-cols-2 gap-3">
                        <Stat label="Users" value={data.resources.users} />
                        <Stat label="Workspaces" value={data.resources.workspaces} />
                        <Stat label="Projects" value={data.resources.projects} />
                        <Stat label="Tasks" value={data.resources.tasks} />
                    </div>
                </Card>

                <Card title="Largest tenants" subtitle="By tracked users">
                    <div className="space-y-3">
                        {data.top_tenants.length === 0 && <p className="text-sm text-gray-400">No tenants yet.</p>}
                        {data.top_tenants.map((t) => (
                            <Link
                                key={t.id}
                                to={`/tenants/${t.id}`}
                                className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm"
                            >
                                <span className="truncate font-medium text-gray-700">{t.tenant.name}</span>
                                <span className="flex items-center gap-2">
                                    <span className="text-xs text-gray-400">{t.users} users</span>
                                    <Badge>{STATUS_LABEL[t.tenant.status] || t.tenant.status}</Badge>
                                </span>
                            </Link>
                        ))}
                    </div>
                </Card>

                <Card title="Subscriptions" subtitle="Central subscription rows">
                    <div className="space-y-2">
                        {data.subscriptions.by_status.length === 0 && (
                            <p className="text-sm text-gray-400">No subscriptions yet.</p>
                        )}
                        {data.subscriptions.by_status.map((s) => (
                            <div key={s.status} className="flex items-center justify-between text-sm">
                                <span className="capitalize text-gray-600">{s.status}</span>
                                <span className="font-semibold text-gray-800">{s.count}</span>
                            </div>
                        ))}
                        {data.subscriptions.by_status.length > 0 && (
                            <div className="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 text-sm font-medium">
                                <span className="text-gray-500">Total</span>
                                <span className="font-bold text-gray-800">{data.subscriptions.total}</span>
                            </div>
                        )}
                    </div>
                </Card>
            </div>
        </div>
    );
}