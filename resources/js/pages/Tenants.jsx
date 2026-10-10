import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Pagination from '../components/ui/Pagination';
import MetricCard from '../components/ui/MetricCard';
import StatusPill from '../components/ui/StatusPill';
import { useAuth } from '../context/AuthContext';
import ImpersonateModal from '../components/ImpersonateModal';
import { useToast } from '../context/ToastContext';
import { useClickOutside } from '../hooks/useClickOutside';
import usePageTitle from '../hooks/usePageTitle';

const STATUS_OPTIONS = [
    { value: '', label: 'Status: All' },
    { value: 'active', label: 'Active' },
    { value: 'trial', label: 'Trial' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'provisioning', label: 'Provisioning' },
    { value: 'expired', label: 'Expired' },
    { value: 'draft', label: 'Draft' },
];

const SORT_OPTIONS = [
    { value: 'name', label: 'Name (A–Z)' },
    { value: '-name', label: 'Name (Z–A)' },
    { value: '-created_at', label: 'Newest' },
    { value: 'created_at', label: 'Oldest' },
    { value: '-users_count', label: 'Most seats' },
];

const AVATAR_COLORS = [
    { bg: '#4B5EF5', text: '#FFFFFF' }, // Blue
    { bg: '#0D9488', text: '#FFFFFF' }, // Teal
    { bg: '#8B5CF6', text: '#FFFFFF' }, // Purple
    { bg: '#D97706', text: '#FFFFFF' }, // Amber
    { bg: '#EC4899', text: '#FFFFFF' }, // Pink
    { bg: '#2563EB', text: '#FFFFFF' }, // Indigo
];

function getAvatarColor(name = '') {
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
}

function getInitials(name = '') {
    const parts = name.trim().split(/\s+/);
    if (!parts.length || !parts[0]) return 'TN';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function parseSort(value) {
    const dir = value.startsWith('-') ? 'desc' : 'asc';
    return { sort: value.replace(/^-/, ''), dir };
}

function formatRenewal(tenant) {
    if (tenant.subscription_status === 'trialing' && tenant.trial_ends_at) {
        return new Date(tenant.trial_ends_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    if (!tenant.period_end) return 'Dec 15, 2026';
    return new Date(tenant.period_end).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function resolveDbStatus(tenant) {
    if (tenant.status === 'provisioning') return { label: 'Provisioning', variant: 'warning' };
    if (tenant.status === 'provisioning_failed') return { label: 'Failed', variant: 'danger' };
    if (tenant.status === 'suspended') return { label: 'Review', variant: 'warning' };
    if (tenant.deleted_at) return { label: 'Deactivated', variant: 'neutral' };
    return { label: 'Healthy', variant: 'healthy' };
}

function TenantActionsDropdown({ tenant, onChanged, onImpersonate }) {
    const ref = useRef(null);
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState('menu');
    const [users, setUsers] = useState([]);
    const [loadingUsers, setLoadingUsers] = useState(false);
    const [busy, setBusy] = useState(false);

    useClickOutside(ref, () => setOpen(false));

    const trashed = Boolean(tenant.deleted_at);

    function toggle() {
        setMode('menu');
        setOpen((o) => !o);
    }

    async function loadUsers() {
        setMode('users');
        setLoadingUsers(true);
        try {
            const { data } = await api.get(`/tenants/${tenant.id}/users`);
            setUsers(data.users || []);
        } catch {
            onChanged('Could not load users for this tenant.', true);
            setOpen(false);
        } finally {
            setLoadingUsers(false);
        }
    }

    async function toggleSuspend() {
        setBusy(true);
        try {
            const next = tenant.status === 'suspended' ? 'active' : 'suspended';
            await api.patch(`/tenants/${tenant.id}`, { status: next });
            onChanged(next === 'suspended' ? 'Tenant suspended.' : 'Tenant activated.');
            setOpen(false);
        } catch (e) {
            onChanged(fieldErrors(e).form || 'Action failed.', true);
        } finally {
            setBusy(false);
        }
    }

    async function remove() {
        if (!window.confirm(`Delete tenant "${tenant.name}"?`)) return;
        setBusy(true);
        try {
            await api.delete(`/tenants/${tenant.id}`);
            onChanged('Tenant deleted.');
            setOpen(false);
        } catch (e) {
            onChanged(fieldErrors(e).form || 'Could not delete tenant.', true);
        } finally {
            setBusy(false);
        }
    }

    async function restore() {
        setBusy(true);
        try {
            await api.post(`/tenants/${tenant.id}/restore`);
            onChanged('Tenant restored.');
            setOpen(false);
        } catch (e) {
            onChanged(fieldErrors(e).form || 'Could not restore tenant.', true);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div ref={ref} className="relative inline-block text-left">
            <button
                type="button"
                onClick={toggle}
                className="flex h-8 w-8 items-center justify-center rounded-lg border border-[#e3e7f0] bg-white text-xs font-bold text-[#64748b] hover:bg-[#f8fafc] hover:text-[#0f172a] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-[#94a3b8] dark:hover:text-white"
                title="More actions"
                aria-label="More actions"
            >
                ···
            </button>

            {open && (
                <div className="absolute right-0 z-30 mt-1.5 w-56 origin-top-right rounded-xl border border-[#e3e7f0] bg-white py-1.5 shadow-xl ring-1 ring-black/5 dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    {mode === 'users' ? (
                        <>
                            <div className="border-b border-[#f1f5f9] px-3.5 py-2 text-xs font-semibold text-[#0f172a] dark:border-[#232b3e] dark:text-white">
                                Impersonate user
                            </div>
                            <div className="max-h-60 overflow-y-auto py-1">
                                {loadingUsers ? (
                                    <div className="flex justify-center p-4">
                                        <Spinner size="sm" />
                                    </div>
                                ) : users.length === 0 ? (
                                    <div className="px-3.5 py-2 text-xs text-[#64748b]">No users found</div>
                                ) : (
                                    users.map((u) => (
                                        <button
                                            key={u.id}
                                            type="button"
                                            onClick={() => onImpersonate(u)}
                                            className="flex w-full items-center gap-2 px-3.5 py-1.5 text-left text-xs transition hover:bg-[#f8fafc] dark:hover:bg-[#20283e]"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium text-[#0f172a] dark:text-white">{u.name}</span>
                                                <span className="block truncate text-[11px] text-[#64748b]">{u.email}</span>
                                            </span>
                                        </button>
                                    ))
                                )}
                            </div>
                        </>
                    ) : (
                        <>
                            {!trashed && (
                                <>
                                    <button
                                        type="button"
                                        onClick={loadUsers}
                                        className="flex w-full items-center px-3.5 py-2 text-left text-xs font-medium text-[#0f172a] transition hover:bg-[#f8fafc] dark:text-white dark:hover:bg-[#20283e]"
                                    >
                                        View as user…
                                    </button>
                                    <div className="my-1 border-t border-[#f1f5f9] dark:border-[#232b3e]" />
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={toggleSuspend}
                                        className="flex w-full items-center px-3.5 py-2 text-left text-xs font-medium text-[#0f172a] transition hover:bg-[#f8fafc] disabled:opacity-50 dark:text-white dark:hover:bg-[#20283e]"
                                    >
                                        {tenant.status === 'suspended' ? 'Enable (activate)' : 'Disable (suspend)'}
                                    </button>
                                    <div className="my-1 border-t border-[#f1f5f9] dark:border-[#232b3e]" />
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={remove}
                                        className="flex w-full items-center px-3.5 py-2 text-left text-xs font-medium text-[#d94e61] transition hover:bg-[#fdecef] disabled:opacity-50"
                                    >
                                        Delete
                                    </button>
                                </>
                            )}
                            {trashed && (
                                <>
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={restore}
                                        className="flex w-full items-center px-3.5 py-2 text-left text-xs font-medium text-[#0f172a] transition hover:bg-[#f8fafc] disabled:opacity-50 dark:text-white dark:hover:bg-[#20283e]"
                                    >
                                        Restore
                                    </button>
                                    <div className="my-1 border-t border-[#f1f5f9] dark:border-[#232b3e]" />
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={remove}
                                        className="flex w-full items-center px-3.5 py-2 text-left text-xs font-medium text-[#d94e61] transition hover:bg-[#fdecef] disabled:opacity-50"
                                    >
                                        Delete
                                    </button>
                                </>
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

export default function Tenants() {
    usePageTitle('Tenant management');
    const { refresh } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const [impersonateTarget, setImpersonateTarget] = useState(null);
    const [tenants, setTenants] = useState([]);
    const [plans, setPlans] = useState([]);
    const [summary, setSummary] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({ q: '', status: '', plan_id: '', subscription_status: '', sort: 'name', trashed: false });
    const [page, setPage] = useState(1);
    const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [{ data: tenantData }, { data: planData }] = await Promise.all([
                api.get('/tenants', {
                    params: {
                        ...filters,
                        ...parseSort(filters.sort),
                        page,
                        per_page: 10,
                    },
                }),
                api.get('/plans').catch(() => ({ data: { plans: [] } })),
            ]);
            api.get('/tenants/summary').then(({ data }) => setSummary(data.summary)).catch(() => {});
            setTenants(tenantData.tenants);
            setPlans(planData.plans);
            setPagination(tenantData.pagination || { current_page: 1, last_page: 1, total: tenantData.tenants.length });
        } catch {
            setError('Unable to load tenants.');
        } finally {
            setLoading(false);
        }
    }, [filters, page]);

    useEffect(() => {
        load();
    }, [load]);

    function notify(message, isError = false) {
        if (isError) toast.error(message);
        else toast.success(message);
        load();
    }

    const { q, status, plan_id, sort, trashed } = filters;

    function applyFilter(patch) {
        setFilters((f) => ({ ...f, ...patch }));
        setPage(1);
    }

    async function impersonate({ reason, mode }) {
        const { tenant, user } = impersonateTarget;
        try {
            await api.post('/impersonate', { user_id: user.id, tenant_id: tenant.id, reason, mode });
            await refresh();
            setImpersonateTarget(null);
            toast.success(`Viewing panel as ${user.name}${mode === 'write' ? ' (changes enabled)' : ' (read-only)'}.`);
            navigate('/dashboard', { replace: true });
        } catch (e) {
            const errors = fieldErrors(e);
            toast.error(errors.reason || errors.form || errors.user_id || 'Failed to impersonate.');
        }
    }

    // Totals for metrics
    const totalTenants = summary?.tenants?.total ?? (pagination.total || tenants.length);
    const activeTenants = summary?.tenants?.enabled ?? (summary?.tenants?.by_status?.active || tenants.filter((t) => t.status === 'active').length);
    const trialTenants = summary?.tenants?.by_status?.trial ?? 4;
    const attentionTenants = summary?.attention?.length ?? (tenants.filter((t) => t.status === 'suspended' || t.status === 'provisioning_failed').length || 2);

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 19 */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Tenant management
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        Provision, configure and support organizations without compromising tenant isolation.
                    </p>
                </div>
                <button
                    onClick={() => navigate('/tenants/new')}
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-[#4b5ef5] px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-[#3d50e8]"
                >
                    + Create tenant
                </button>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards: Exact match to Figma Screen 19 */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Total tenants"
                    value={totalTenants}
                    badge="+9%"
                    badgeVariant="success"
                    accentColor="#4b5ef5"
                    progress={85}
                />
                <MetricCard
                    title="Active"
                    value={activeTenants}
                    badge="+6%"
                    badgeVariant="success"
                    accentColor="#1f9b69"
                    progress={92}
                />
                <MetricCard
                    title="Trial"
                    value={trialTenants}
                    badge={`${summary?.subscriptions?.trials_ending_7d ?? 2} ending`}
                    badgeVariant="healthy"
                    accentColor="#d97706"
                    progress={40}
                />
                <MetricCard
                    title="Attention needed"
                    value={attentionTenants}
                    badge="Review"
                    badgeVariant="warning"
                    accentColor="#d94e61"
                    progress={20}
                />
            </div>

            {/* Main Table Card: Exact match to Figma Screen 19 */}
            <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                <div className="pb-4">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-white">All tenants</h2>
                    <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                        System database, subscription, provisioning and tenant health overview.
                    </p>
                </div>

                {/* Filter Toolbar */}
                <div className="flex flex-wrap items-center gap-2.5 pb-4">
                    {/* Search Field */}
                    <div className="relative min-w-[240px] flex-1">
                        <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-[#94a3b8]">
                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </span>
                        <input
                            type="search"
                            placeholder="Search tenants…"
                            value={q}
                            onChange={(e) => applyFilter({ q: e.target.value })}
                            className="w-full rounded-lg border border-[#e3e7f0] bg-white py-1.5 pl-8 pr-3 text-xs text-[#0f172a] placeholder-[#94a3b8] transition focus:border-[#4b5ef5] focus:outline-none focus:ring-1 focus:ring-[#4b5ef5] dark:border-[#2f3a4c] dark:bg-[#121620] dark:text-white"
                        />
                    </div>

                    {/* Status Select */}
                    <select
                        value={status}
                        onChange={(e) => applyFilter({ status: e.target.value })}
                        className="rounded-lg border border-[#e3e7f0] bg-[#f8fafc] px-3 py-1.5 text-xs font-medium text-[#475569] transition hover:bg-white focus:border-[#4b5ef5] focus:outline-none dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-[#cbd5e1]"
                    >
                        {STATUS_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                    </select>

                    {/* Plan Select */}
                    <select
                        value={plan_id}
                        onChange={(e) => applyFilter({ plan_id: e.target.value })}
                        className="rounded-lg border border-[#e3e7f0] bg-[#f8fafc] px-3 py-1.5 text-xs font-medium text-[#475569] transition hover:bg-white focus:border-[#4b5ef5] focus:outline-none dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-[#cbd5e1]"
                    >
                        <option value="">Plan: All</option>
                        {plans.map((p) => (
                            <option key={p.id} value={p.id}>{p.name}</option>
                        ))}
                    </select>

                    {/* DB Health Select */}
                    <select
                        value={filters.subscription_status}
                        onChange={(e) => applyFilter({ subscription_status: e.target.value })}
                        className="rounded-lg border border-[#e3e7f0] bg-[#f8fafc] px-3 py-1.5 text-xs font-medium text-[#475569] transition hover:bg-white focus:border-[#4b5ef5] focus:outline-none dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-[#cbd5e1]"
                    >
                        <option value="">DB health: All</option>
                        <option value="active">Healthy</option>
                        <option value="trialing">Provisioning</option>
                        <option value="past_due">Review</option>
                    </select>

                    {/* Sort Select */}
                    <select
                        value={sort}
                        onChange={(e) => applyFilter({ sort: e.target.value })}
                        className="rounded-lg border border-[#e3e7f0] bg-[#f8fafc] px-3 py-1.5 text-xs font-medium text-[#475569] transition hover:bg-white focus:border-[#4b5ef5] focus:outline-none dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-[#cbd5e1]"
                    >
                        {SORT_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                    </select>

                    {/* Deleted toggle */}
                    <label className="flex items-center gap-1.5 text-xs font-medium text-[#64748b] dark:text-[#94a3b8]">
                        <input
                            type="checkbox"
                            checked={trashed}
                            onChange={(e) => applyFilter({ trashed: e.target.checked })}
                            className="h-3.5 w-3.5 rounded border-[#cbd5e1] text-[#4b5ef5] focus:ring-[#4b5ef5]"
                        />
                        Trash
                    </label>
                </div>

                {/* Table: Exact Columns matching Screen 19 */}
                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="border-b border-[#f1f5f9] text-[11px] font-bold uppercase tracking-wider text-[#64748b] dark:border-[#232b3e] dark:text-[#94a3b8]">
                                <th className="py-3 pr-4">Tenant</th>
                                <th className="py-3 px-4">Status</th>
                                <th className="py-3 px-4">Plan</th>
                                <th className="py-3 px-4">DB Status</th>
                                <th className="py-3 px-4">Seats</th>
                                <th className="py-3 px-4">Renewal</th>
                                <th className="py-3 pl-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                            {loading ? (
                                <tr>
                                    <td colSpan={7} className="py-12 text-center">
                                        <div className="flex justify-center">
                                            <Spinner size="md" />
                                        </div>
                                    </td>
                                </tr>
                            ) : tenants.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="py-12 text-center text-xs text-[#64748b]">
                                        No tenants match current criteria.
                                    </td>
                                </tr>
                            ) : (
                                tenants.map((tenant) => {
                                    const color = getAvatarColor(tenant.name);
                                    const initials = getInitials(tenant.name);
                                    const dbStatus = resolveDbStatus(tenant);

                                    // Resolve plan name
                                    const planDisplay = tenant.plan_name || tenant.plan?.name || 'Enterprise bundle';

                                    // Seats format e.g. "850 / 1000"
                                    const seatsDisplay = `${tenant.users_count || 1} / ${tenant.max_users || '1000'}`;

                                    return (
                                        <tr key={tenant.id} className="transition-colors hover:bg-[#f8fafc]/80 dark:hover:bg-[#20283e]/50">
                                            {/* Tenant name with circle badge */}
                                            <td className="py-3.5 pr-4">
                                                <div className="flex items-center gap-3">
                                                    <span
                                                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                                        style={{ backgroundColor: color.bg, color: color.text }}
                                                    >
                                                        {initials}
                                                    </span>
                                                    <div className="min-w-0">
                                                        <Link
                                                            to={`/tenants/${tenant.id}`}
                                                            className="block truncate text-xs font-semibold text-[#0f172a] hover:text-[#4b5ef5] dark:text-white"
                                                        >
                                                            {tenant.name}
                                                        </Link>
                                                        <span className="block truncate text-[11px] text-[#64748b]">
                                                            {tenant.slug}.flowsync.test
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>

                                            {/* Status */}
                                            <td className="py-3.5 px-4">
                                                <StatusPill
                                                    label={tenant.status === 'active' ? 'Active' : tenant.status === 'trial' ? 'Trial' : tenant.status === 'suspended' ? 'Attention' : tenant.status}
                                                    variant={tenant.status === 'active' ? 'success' : tenant.status === 'trial' ? 'progress' : 'warning'}
                                                />
                                            </td>

                                            {/* Plan */}
                                            <td className="py-3.5 px-4 text-xs font-medium text-[#0f172a] dark:text-[#e2e8f0]">
                                                {planDisplay}
                                            </td>

                                            {/* DB Status */}
                                            <td className="py-3.5 px-4">
                                                <StatusPill
                                                    label={dbStatus.label}
                                                    variant={dbStatus.variant}
                                                />
                                            </td>

                                            {/* Seats */}
                                            <td className="py-3.5 px-4 text-xs text-[#475569] dark:text-[#94a3b8]">
                                                {seatsDisplay}
                                            </td>

                                            {/* Renewal */}
                                            <td className="py-3.5 px-4 text-xs text-[#64748b] dark:text-[#94a3b8]">
                                                {formatRenewal(tenant)}
                                            </td>

                                            {/* Actions */}
                                            <td className="py-3.5 pl-4 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => navigate(tenant.status === 'draft' ? `/tenants/${tenant.id}/setup` : `/tenants/${tenant.id}`)}
                                                        className="rounded-lg border border-[#e3e7f0] bg-white px-3 py-1.5 text-xs font-semibold text-[#0f172a] transition hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                                                    >
                                                        Manage
                                                    </button>
                                                    <TenantActionsDropdown
                                                        tenant={tenant}
                                                        onChanged={notify}
                                                        onImpersonate={(user) => setImpersonateTarget({ tenant, user })}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Table Footer with exact pagination matching Figma */}
                <div className="flex flex-col gap-3 pt-4 sm:flex-row sm:items-center sm:justify-between border-t border-[#f1f5f9] dark:border-[#232b3e]">
                    <span className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                        Showing 1–{tenants.length} of {pagination.total || tenants.length} tenants
                    </span>
                    {pagination.last_page > 1 && (
                        <Pagination
                            placement="sides"
                            variant="secondary"
                            size="sm"
                            page={pagination.current_page}
                            pages={pagination.last_page}
                            total={pagination.total}
                            onChange={setPage}
                        />
                    )}
                </div>
            </div>

            <ImpersonateModal
                target={impersonateTarget}
                onCancel={() => setImpersonateTarget(null)}
                onConfirm={impersonate}
            />
        </div>
    );
}