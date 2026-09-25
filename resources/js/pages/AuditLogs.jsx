import { useCallback, useEffect, useState } from 'react';
import api from '../services/api';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Button from '../components/ui/Button';
import Pagination from '../components/ui/Pagination';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
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
                                type === key ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'bg-white text-gray-600 hover:bg-gray-50'
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

            {loading ? (
                <div className="flex justify-center py-12">
                    <Spinner />
                </div>
            ) : (
                <Table>
                    <thead>
                        <tr>
                            <Th align="right">When</Th>
                            <Th>Actor</Th>
                            <Th>Action</Th>
                            <Th>Target</Th>
                            <Th>Tenant</Th>
                            <Th align="right">IP</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.length === 0 ? (
                            <TableEmpty colSpan={6}>Nothing logged yet.</TableEmpty>
                        ) : (
                            items.map((item, index) => {
                                const targetId = item.subject_id ?? item.data?.impersonated_user_id ?? null;

                                return (
                                    <tr
                                        key={item.id}
                                        className="animate-fade-in transition-colors duration-150 hover:bg-gray-50/60"
                                        style={{ animationDelay: `${index * 30}ms` }}
                                    >
                                        <Td align="right" className="whitespace-nowrap text-xs text-gray-400">
                                            {item.created_at ? new Date(item.created_at).toLocaleString() : '—'}
                                        </Td>
                                        <Td>
                                            <span className="block truncate font-medium text-gray-800">
                                                {item.actor?.name || 'System'}
                                            </span>
                                            {item.actor?.email && (
                                                <span className="block truncate text-xs text-gray-400">{item.actor.email}</span>
                                            )}
                                        </Td>
                                        <Td>
                                            <Badge>{item.type === 'impersonation' ? 'impersonation' : 'event'}</Badge>
                                            <span className="mt-0.5 block truncate font-medium text-gray-800">
                                                {describe(item.action, item.data)}
                                            </span>
                                        </Td>
                                        <Td className="whitespace-nowrap text-gray-600">
                                            {item.subject_type || targetId ? (
                                                <span>
                                                    {item.subject_type ?? '—'}
                                                    {targetId ? <span className="tabular-nums"> #{targetId}</span> : null}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {item.tenant?.name ?? <span className="text-gray-400">—</span>}
                                        </Td>
                                        <Td align="right" className="whitespace-nowrap text-xs text-gray-400">
                                            {item.ip_address ?? '—'}
                                        </Td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </Table>
            )}

            {pagination && (
                <Pagination
                    placement="sides"
                    page={pagination.current_page}
                    pages={pagination.last_page}
                    onChange={fetchLogs}
                />
            )}
        </div>
    );
}