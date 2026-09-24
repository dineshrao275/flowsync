import { useCallback, useEffect, useState } from 'react';
import api from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Button from '../components/ui/Button';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

const TYPE_LABEL = { all: 'All activity', audit: 'System events', impersonation: 'Impersonations' };

function describe(action, data) {
    switch (action) {
        case 'impersonation.started':
        case 'impersonation.ended':
            return action === 'impersonation.started' ? 'Started impersonating tenant user' : 'Stopped impersonating tenant user';
        case 'platform.settings_updated':
            return `Updated platform settings (${(data?.keys || []).join(', ')})`;
        case 'system.user_created':
            return `Created platform admin ${data?.email}`;
        case 'plan.module_toggled':
            return `${data?.enabled ? 'Enabled' : 'Disabled'} module ${data?.module}`;
        case 'tenant.status_changed':
            return `Tenant → ${data?.to}`;
        case 'tenant.deleted':
        case 'tenant.restored':
            return action.split('.').map((w) => w[0].toUpperCase() + w.slice(1)).join(' ');
        default:
            return action;
    }
}

export default function AuditLogs() {
    usePageTitle('Audit Logs');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Audit Logs' }]);
    const [items, setItems] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [type, setType] = useState('all');
    const [q, setQ] = useState('');
    const [loading, setLoading] = useState(true);

    const fetchLogs = useCallback((page = 1) => {
        setLoading(true);
        api.get('/system/audit-logs', { params: { type, q: q || undefined, page } })
            .then(({ data }) => {
                setItems(data.items);
                setPagination(data.pagination);
            })
            .finally(() => setLoading(false));
    }, [type, q]);

    useEffect(() => fetchLogs(), [fetchLogs]);

    function search(e) {
        e.preventDefault();
        fetchLogs(1);
    }

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">Audit Logs</h1>
                <p className="text-sm text-gray-500">Platform events + impersonations (latest 100 per source).</p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <div className="flex overflow-hidden rounded-lg border border-gray-200">
                    {Object.entries(TYPE_LABEL).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setType(key)}
                            className={`px-3 py-2 text-sm font-medium ${
                                type === key ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <form onSubmit={search} className="flex gap-2">
                    <input
                        type="search"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Filter action, actor…"
                        className="w-56 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-indigo-400"
                    />
                    <Button type="submit">Filter</Button>
                </form>
            </div>

            <Card>
                {loading ? (
                    <Spinner />
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {items.map((item) => (
                            <li key={item.id} className="flex items-start gap-3 py-3">
                                <Badge>{item.type === 'impersonation' ? 'impersonation' : 'event'}</Badge>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-gray-800">{describe(item.action, item.data)}</p>
                                    <p className="truncate text-xs text-gray-400">
                                        {item.actor?.name || 'System'} {item.actor?.email ? `(${item.actor.email})` : ''}
                                        {item.tenant?.name ? ` · ${item.tenant.name}` : ''}
                                        {item.subject_type ? ` · ${item.subject_type}` : ''}
                                    </p>
                                </div>
                                <span className="shrink-0 text-xs text-gray-400">
                                    {item.created_at ? new Date(item.created_at).toLocaleString() : '—'}
                                </span>
                            </li>
                        ))}
                        {items.length === 0 && (
                            <li className="py-6 text-center text-gray-400">Nothing logged yet.</li>
                        )}
                    </ul>
                )}
            </Card>

            {pagination && pagination.last_page > 1 && (
                <div className="flex items-center justify-between text-sm">
                    <span className="text-gray-400">Page {pagination.current_page} of {pagination.last_page}</span>
                    <div className="flex gap-2">
                        <Button variant="ghost" disabled={pagination.current_page <= 1} onClick={() => fetchLogs(pagination.current_page - 1)}>Prev</Button>
                        <Button variant="ghost" disabled={pagination.current_page >= pagination.last_page} onClick={() => fetchLogs(pagination.current_page + 1)}>Next</Button>
                    </div>
                </div>
            )}
        </div>
    );
}