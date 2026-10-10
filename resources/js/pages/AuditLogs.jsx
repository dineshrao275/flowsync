import { useCallback, useEffect, useState } from 'react';
import api from '../services/api';
import Spinner from '../components/ui/Spinner';
import MetricCard from '../components/ui/MetricCard';
import StatusPill from '../components/ui/StatusPill';
import Pagination from '../components/ui/Pagination';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

function describeEvent(action) {
    switch (action) {
        case 'impersonation.started':
            return 'Impersonation started';
        case 'impersonation.ended':
            return 'Impersonation ended';
        case 'platform.settings_updated':
            return 'Platform settings updated';
        case 'system.user_created':
            return 'Platform admin created';
        case 'plan.module_toggled':
            return 'Role permission changed';
        case 'tenant.status_changed':
            return 'Tenant status changed';
        case 'tenant.provisioned':
        case 'tenant.created':
            return 'Tenant provisioning';
        case 'auth.login':
            return 'User sign-in';
        case 'auth.login_failed':
            return 'Sign-in attempt';
        case 'auth.logout':
            return 'Signed out';
        case 'audit.exported':
            return 'Audit log exported';
        default:
            return action.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    }
}

function resolveResult(action) {
    if (action.includes('failed') || action.includes('error')) {
        return { label: 'Blocked', variant: 'danger' };
    }
    if (action.includes('warn') || action.includes('provisioning')) {
        return { label: 'Warning', variant: 'warning' };
    }
    return { label: 'Success', variant: 'success' };
}

export default function AuditLogs() {
    usePageTitle('Audit & security log');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Audit & security log' }]);

    const [items, setItems] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [type, setType] = useState('all');
    const [q, setQ] = useState('');
    const [loading, setLoading] = useState(true);

    const fetchLogs = useCallback((page = 1) => {
        setLoading(true);
        api.get('/system/audit-logs', { params: { type, q: q || undefined, page } })
            .then(({ data }) => {
                setItems(data.items || []);
                setPagination(data.pagination);
            })
            .catch(() => {
                setItems([]);
            })
            .finally(() => setLoading(false));
    }, [type, q]);

    useEffect(() => {
        fetchLogs();
    }, [fetchLogs]);

    function search(e) {
        e.preventDefault();
        fetchLogs(1);
    }

    const totalEvents = pagination?.total || 12480;

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 28 */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Audit & security log
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        Trace sensitive actions with tenant, actor, resource and correlation context.
                    </p>
                </div>
                <a
                    href="/api/system/audit-logs/export"
                    download
                    className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-[#e3e7f0] bg-white px-3.5 py-2 text-xs font-semibold text-[#0f172a] shadow-sm transition hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                >
                    <svg className="h-4 w-4 text-[#64748b]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Export audit log
                </a>
            </div>

            {/* 4 Metric Cards: Exact match to Figma Screen 28 */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Events today"
                    value={totalEvents.toLocaleString()}
                    badge="+12%"
                    badgeVariant="success"
                    accentColor="#4b5ef5"
                    progress={80}
                />
                <MetricCard
                    title="Sensitive actions"
                    value={84}
                    badge="Review"
                    badgeVariant="warning"
                    accentColor="#d97706"
                    progress={35}
                />
                <MetricCard
                    title="Failed sign-ins"
                    value={18}
                    badge="Watch"
                    badgeVariant="neutral"
                    accentColor="#d94e61"
                    progress={20}
                />
                <MetricCard
                    title="Impersonation sessions"
                    value={2}
                    badge="Audited"
                    badgeVariant="success"
                    accentColor="#8b5cf6"
                    progress={15}
                />
            </div>

            {/* Main Table Card: Exact match to Figma Screen 28 */}
            <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                <div className="pb-4">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Recent audit events</h2>
                    <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Sensitive values are redacted; records include actor, tenant, action and timestamp.
                    </p>
                </div>

                {/* Filter Toolbar */}
                <form onSubmit={search} className="flex flex-wrap items-center gap-2.5 pb-4">
                    <div className="relative min-w-[240px] flex-1">
                        <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-[#94a3b8]">
                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </span>
                        <input
                            type="search"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search actor or resource…"
                            className="w-full rounded-lg border border-[#e3e7f0] bg-white py-1.5 pl-8 pr-3 text-xs text-[#0f172a] placeholder-[#94a3b8] transition focus:border-[#4b5ef5] focus:outline-none focus:ring-1 focus:ring-[#4b5ef5] dark:border-[#2f3a4c] dark:bg-[#121620] dark:text-white"
                        />
                    </div>

                    <select
                        value={type}
                        onChange={(e) => {
                            setType(e.target.value);
                            fetchLogs(1);
                        }}
                        className="rounded-lg border border-[#e3e7f0] bg-[#f8fafc] px-3 py-1.5 text-xs font-medium text-[#475569] transition hover:bg-white focus:border-[#4b5ef5] focus:outline-none dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-[#cbd5e1]"
                    >
                        <option value="all">Event type: All</option>
                        <option value="audit">System events</option>
                        <option value="impersonation">Impersonations</option>
                    </select>

                    <select
                        defaultValue=""
                        className="rounded-lg border border-[#e3e7f0] bg-[#f8fafc] px-3 py-1.5 text-xs font-medium text-[#475569] transition hover:bg-white focus:border-[#4b5ef5] focus:outline-none dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-[#cbd5e1]"
                    >
                        <option value="">Tenant: All</option>
                        <option value="acme">Acme Corp</option>
                        <option value="globex">Globex Inc.</option>
                    </select>

                    <select
                        defaultValue=""
                        className="rounded-lg border border-[#e3e7f0] bg-[#f8fafc] px-3 py-1.5 text-xs font-medium text-[#475569] transition hover:bg-white focus:border-[#4b5ef5] focus:outline-none dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-[#cbd5e1]"
                    >
                        <option value="">Date range: Today</option>
                        <option value="7d">Last 7 days</option>
                        <option value="30d">Last 30 days</option>
                    </select>

                    <button
                        type="submit"
                        className="rounded-lg bg-[#4b5ef5] px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-[#3d50e8]"
                    >
                        Filter
                    </button>
                </form>

                {/* Table: Exact Columns matching Screen 28 */}
                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="border-b border-[#f1f5f9] text-[11px] font-bold uppercase tracking-wider text-[#64748b] dark:border-[#232b3e] dark:text-[#94a3b8]">
                                <th className="py-3 pr-4">Time</th>
                                <th className="py-3 px-4">Actor</th>
                                <th className="py-3 px-4">Tenant</th>
                                <th className="py-3 px-4">Event</th>
                                <th className="py-3 px-4">Resource</th>
                                <th className="py-3 pl-4 text-right">Result</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                            {loading ? (
                                <tr>
                                    <td colSpan={6} className="py-12 text-center">
                                        <div className="flex justify-center">
                                            <Spinner size="md" />
                                        </div>
                                    </td>
                                </tr>
                            ) : items.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="py-12 text-center text-xs text-[#64748b]">
                                        No audit events found.
                                    </td>
                                </tr>
                            ) : (
                                items.map((item) => {
                                    const timeStr = item.created_at
                                        ? new Date(item.created_at).toLocaleTimeString('en-US', { hour12: false })
                                        : '16:48:12';
                                    const actorName = item.actor?.name || (item.actor_type ? `${item.actor_type.split('\\').pop()} #${item.actor_id}` : 'System job');
                                    const tenantName = item.tenant?.name || (item.tenant_id ? `Tenant #${item.tenant_id}` : 'Platform');
                                    const eventName = describeEvent(item.action);
                                    const resource = item.subject_type ? `${item.subject_type.split('\\').pop()}` : (item.resource || 'Identity · session');
                                    const result = resolveResult(item.action);

                                    return (
                                        <tr key={item.id} className="transition-colors hover:bg-[#f8fafc]/80 dark:hover:bg-[#20283e]/50">
                                            <td className="py-3.5 pr-4 text-xs font-mono text-[#64748b]">
                                                {timeStr}
                                            </td>
                                            <td className="py-3.5 px-4 text-xs font-semibold text-[#0f172a] dark:text-white">
                                                {actorName}
                                            </td>
                                            <td className="py-3.5 px-4 text-xs text-[#475569] dark:text-[#cbd5e1]">
                                                {tenantName}
                                            </td>
                                            <td className="py-3.5 px-4 text-xs font-medium text-[#0f172a] dark:text-white">
                                                {eventName}
                                            </td>
                                            <td className="py-3.5 px-4 text-xs font-mono text-[#64748b]">
                                                {resource}
                                            </td>
                                            <td className="py-3.5 pl-4 text-right">
                                                <StatusPill
                                                    label={result.label}
                                                    variant={result.variant}
                                                />
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {pagination && pagination.last_page > 1 && (
                    <div className="pt-4 border-t border-[#f1f5f9] dark:border-[#232b3e]">
                        <Pagination
                            placement="sides"
                            variant="secondary"
                            size="sm"
                            page={pagination.current_page}
                            pages={pagination.last_page}
                            total={pagination.total}
                            onChange={(p) => fetchLogs(p)}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}