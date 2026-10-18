import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Pagination from '../components/ui/Pagination';
import { useAuth } from '../context/AuthContext';
import ImpersonateModal from '../components/ImpersonateModal';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import FloatingPanel from '../components/ui/FloatingPanel';
import usePageTitle from '../hooks/usePageTitle';
import i18n, { intlTag } from '../i18n';
import Timestamp from '../components/ui/Timestamp';

const STATUS_OPTIONS = [
    { value: 'pending', key: 'pending' },
    { value: 'provisioning', key: 'provisioning' },
    { value: 'draft', key: 'draft' },
    { value: 'trial', key: 'trial' },
    { value: 'active', key: 'active' },
    { value: 'suspended', key: 'suspended' },
    { value: 'expired', key: 'expired' },
    { value: 'deactivated', key: 'deactivated' },
    { value: 'provisioning_failed', key: 'provisioning_failed' },
];

const STATUS_STYLES = {
    active: 'bg-emerald-100 text-emerald-700',
    trial: 'bg-sky-100 text-sky-700',
    suspended: 'bg-amber-100 text-amber-700',
    expired: 'bg-rose-100 text-rose-700',
    deactivated: 'bg-gray-200 text-gray-600',
    pending: 'bg-gray-100 text-gray-600',
    draft: 'bg-yellow-100 text-yellow-800',
    provisioning: 'bg-[var(--accent-soft)] text-[var(--accent)]',
    provisioning_failed: 'bg-rose-100 text-rose-700',
};

const SORT_OPTIONS = [
    { value: 'name', key: 'nameAsc' },
    { value: '-name', key: 'nameDesc' },
    { value: '-created_at', key: 'newest' },
    { value: 'created_at', key: 'oldest' },
    { value: '-users_count', key: 'mostUsers' },
    { value: '-updated_at', key: 'recentlyUpdated' },
];

function parseSort(value) {
    const dir = value.startsWith('-') ? 'desc' : 'asc';
    return { sort: value.replace(/^-/, ''), dir };
}

/** "Trial ends in 4d", "Renews 12 Nov", "Ends 3 Nov" — whichever date matters for this tenant. */
function renewalLabel(t, tenant) {
    const fmt = (v) => new Date(v).toLocaleDateString(intlTag(i18n.language), { day: 'numeric', month: 'short' });
    if (tenant.subscription_status === 'trialing' && tenant.trial_ends_at) {
        const days = Math.ceil((new Date(tenant.trial_ends_at) - Date.now()) / 86400000);
        return days <= 0 ? t('admin.tenants.renewalTrialToday') : t('admin.tenants.renewalTrialIn', { days });
    }
    if (!tenant.period_end) return '—';
    if (tenant.auto_renew === false || tenant.subscription_status === 'canceled') return t('admin.tenants.renewalEnds', { date: fmt(tenant.period_end) });
    return t('admin.tenants.renewalRenews', { date: fmt(tenant.period_end) });
}

function SummaryCard({ label, value, hint }) {
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-400">{label}</p>
            <p className="mt-1 text-2xl font-bold text-gray-900">{value}</p>
            <p className="mt-0.5 text-xs text-gray-500">{hint}</p>
        </div>
    );
}

function StatusBadge({ status }) {
    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${
                STATUS_STYLES[status] || 'bg-gray-100 text-gray-700'
            }`}
        >
            {status?.replace('_', ' ')}
        </span>
    );
}

function TenantActions({ tenant, onChanged, onImpersonate }) {
    const { t } = useTranslation();
    const ref = useRef(null);
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState('menu');
    const [users, setUsers] = useState([]);
    const [loadingUsers, setLoadingUsers] = useState(false);
    const [busy, setBusy] = useState(false);

    const panelRef = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const onDown = (event) => {
            if (!ref.current?.contains(event.target) && !panelRef.current?.contains(event.target)) setOpen(false);
        };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    const confirm = useConfirm();
    const trashed = Boolean(tenant.deleted_at);

    function toggle() {
        setOpen((current) => {
            if (!current) setMode('menu');
            return !current;
        });
    }

    async function loadUsers() {
        setMode('users');
        setLoadingUsers(true);
        try {
            const { data } = await api.get(`/tenants/${tenant.id}/users`);
            setUsers(data.users);
        } finally {
            setLoadingUsers(false);
        }
    }

    async function toggleSuspend() {
        const activating = tenant.status === 'suspended';
        const action = activating ? 'activate' : 'suspend';
        setBusy(true);
        try {
            await api.post(`/tenants/${tenant.id}/${action}`);
            onChanged(activating ? t('admin.tenants.tenantActivated') : t('admin.tenants.tenantSuspended'));
        } catch (e) {
            onChanged(fieldErrors(e).form || t(activating ? 'admin.tenants.unableActivate' : 'admin.tenants.unableSuspend'), true);
            setOpen(false);
        } finally {
            setBusy(false);
            setOpen(false);
        }
    }

    async function remove() {
        if (!(await confirm({ title: t('admin.tenants.deleteTitle'), message: t('admin.tenants.deleteMessage', { name: tenant.name }), variant: 'danger' }))) return;
        setBusy(true);
        try {
            await api.delete(`/tenants/${tenant.id}`);
            onChanged(t('admin.tenants.deleted'));
        } catch (e) {
            onChanged(fieldErrors(e).form || t('admin.tenants.unableDelete'), true);
        } finally {
            setBusy(false);
            setOpen(false);
        }
    }

    async function restore() {
        setBusy(true);
        try {
            await api.post(`/tenants/${tenant.id}/restore`);
            onChanged(t('admin.tenants.restored'));
        } catch (e) {
            onChanged(fieldErrors(e).form || t('admin.tenants.unableRestore'), true);
        } finally {
            setBusy(false);
            setOpen(false);
        }
    }

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                aria-label={t('admin.tenants.actionsLabel')}
                onClick={toggle}
                className="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
            >
                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="5" r="1" />
                    <circle cx="12" cy="12" r="1" />
                    <circle cx="12" cy="19" r="1" />
                </svg>
            </button>

            {open && (
                <FloatingPanel ref={panelRef} anchorRef={ref} className="w-52 origin-top-right animate-scale-in overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl">
                    {mode === 'users' ? (
                        <>
                            <button
                                type="button"
                                onClick={() => setMode('menu')}
                                className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-500 transition hover:bg-gray-50"
                            >
                                <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M19 12H5M12 19l-7-7 7-7" />
                                </svg>
                                {t('admin.tenants.back')}
                            </button>
                            <p className="border-b border-gray-100 px-4 py-2 text-xs font-medium text-gray-400">
                                {t('admin.tenants.viewAsUser')}
                            </p>
                            <div className="max-h-64 overflow-y-auto">
                                {loadingUsers ? (
                                    <p className="px-4 py-3 text-sm text-gray-400">{t('admin.common.loading')}</p>
                                ) : (
                                    users.map((user) => (
                                        <button
                                            key={user.id}
                                            type="button"
                                            onClick={() => onImpersonate(user)}
                                            className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition hover:bg-gray-50"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium text-gray-800">{user.name}</span>
                                                <span className="block truncate text-xs text-gray-500">{user.email}</span>
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
                                    {tenant.status === 'draft' ? (
                                        <ActionLink to={`/tenants/${tenant.id}/setup`} onClick={() => setOpen(false)}>
                                            {t('admin.tenants.continueSetup')}
                                        </ActionLink>
                                    ) : (
                                        <ActionLink to={`/tenants/${tenant.id}`} onClick={() => setOpen(false)}>
                                            {t('admin.tenants.viewEdit')}
                                        </ActionLink>
                                    )}
                                    <ActionButton onClick={loadUsers}>
                                        {t('admin.tenants.viewAsUserMenu')}
                                    </ActionButton>
                                    <div className="my-1 border-t border-gray-100" />
                                    <ActionButton disabled={busy} onClick={toggleSuspend}>
                                        {tenant.status === 'suspended' ? t('admin.tenants.enableActivate') : t('admin.tenants.disableSuspend')}
                                    </ActionButton>
                                    <div className="my-1 border-t border-gray-100" />
                                    <ActionButton disabled={busy} onClick={remove} danger>
                                        {t('admin.common.delete')}
                                    </ActionButton>
                                </>
                            )}
                            {trashed && (
                                <>
                                    <ActionButton disabled={busy} onClick={restore}>
                                        {t('admin.tenants.restoreAction')}
                                    </ActionButton>
                                    <div className="my-1 border-t border-gray-100" />
                                    <ActionButton disabled={busy} onClick={remove} danger>
                                        {t('admin.common.delete')}
                                    </ActionButton>
                                </>
                            )}
                        </>
                    )}
                </FloatingPanel>
            )}
        </div>
    );
}

function ActionLink({ to, onClick, children }) {
    return (
        <Link
            to={to}
            onClick={onClick}
            className="flex w-full items-center px-4 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50"
        >
            {children}
        </Link>
    );
}

function ActionButton({ children, onClick, disabled, danger = false }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className={`flex w-full items-center px-4 py-2 text-left text-sm transition hover:bg-gray-50 disabled:opacity-50 ${danger ? 'text-red-600' : 'text-gray-700'}`}
        >
            {children}
        </button>
    );
}

export default function Tenants() {
    const { t } = useTranslation();
    usePageTitle(t('admin.tenants.pageTitle'));
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
            setError(t('admin.tenants.loadFailed'));
        } finally {
            setLoading(false);
        }
    }, [filters, page, t]);

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

    async function impersonate({ reason, mode, grant_id }) {
        const { tenant, user } = impersonateTarget;
        try {
            await api.post('/impersonate', { user_id: user.id, tenant_id: tenant.id, reason, mode, grant_id });
            await refresh();
            setImpersonateTarget(null);
            toast.success(t(mode === 'write' ? 'admin.tenants.viewingAsWrite' : 'admin.tenants.viewingAsRead', { name: user.name }));
            navigate('/dashboard', { replace: true });
        } catch (e) {
            const errors = fieldErrors(e);
            toast.error(errors.reason || errors.form || errors.user_id || errors.grant_id || errors.mode || t('admin.tenants.impersonateFailed'));
        }
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('admin.tenants.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        {t('admin.tenants.subtitle')}
                    </p>
                </div>
                <Button size="md" onClick={() => navigate('/tenants/new')}>
                    {t('admin.tenants.newTenant')}
                </Button>
            </div>

            {error && <Alert>{error}</Alert>}

            {summary && (
                <div className="space-y-3">
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                        <SummaryCard label={t('admin.tenants.summaryTenants')} value={summary.tenants.total} hint={t('admin.tenants.summaryTenantsHint', { enabled: summary.tenants.enabled, disabled: summary.tenants.disabled })} />
                        <SummaryCard label={t('admin.tenants.summaryOnTrial')} value={summary.tenants.by_status.trial || 0} hint={t('admin.tenants.summaryOnTrialHint', { count: summary.subscriptions.trials_ending_7d })} />
                        <SummaryCard label={t('admin.tenants.summaryPaying')} value={summary.subscriptions.by_status.active || 0} hint={t('admin.tenants.summaryPayingHint', { count: summary.subscriptions.by_status.past_due || 0 })} />
                        <SummaryCard label={t('admin.tenants.summaryMrr')} value={new Intl.NumberFormat(intlTag(i18n.language), { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(summary.mrr_cents / 100)} hint={t('admin.tenants.summaryMrrHint')} />
                        <SummaryCard label={t('admin.tenants.summaryDrafts')} value={summary.tenants.by_status.draft || 0} hint={t('admin.tenants.summaryDraftsHint')} />
                    </div>
                    {summary.attention.length > 0 && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm">
                            <p className="mb-1 font-semibold text-amber-900">{t('admin.tenants.needsAttention')}</p>
                            <ul className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                                {summary.attention.map((a, i) => (
                                    <li key={`${a.tenant_id}-${i}`} className="flex justify-between gap-3">
                                        <Link className="font-medium text-amber-900 hover:underline" to={`/tenants/${a.tenant_id}`}>{a.name}</Link>
                                        <span className="text-amber-800">{a.reason}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}

            <Card className="p-0">
                <div className="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-3">
                    <input
                        type="search"
                        placeholder={t('admin.tenants.searchPlaceholder')}
                        value={q}
                        onChange={(e) => applyFilter({ q: e.target.value })}
                        className="min-w-48 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[var(--accent)]"
                    />
                    <select
                        value={status}
                        onChange={(e) => applyFilter({ status: e.target.value })}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[var(--accent)]"
                    >
                        <option value="">{t('admin.tenants.allStatuses')}</option>
                        {STATUS_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {t(`admin.tenants.statuses.${option.key}`)}
                            </option>
                        ))}
                    </select>
                    <select
                        value={plan_id}
                        onChange={(e) => applyFilter({ plan_id: e.target.value })}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[var(--accent)]"
                    >
                        <option value="">{t('admin.tenants.allPlans')}</option>
                        {plans.map((plan) => (
                            <option key={plan.id} value={plan.id}>
                                {plan.name}
                            </option>
                        ))}
                    </select>
                    <select
                        value={filters.subscription_status}
                        onChange={(e) => applyFilter({ subscription_status: e.target.value })}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[var(--accent)]"
                    >
                        <option value="">{t('admin.tenants.anySubscription')}</option>
                        <option value="trialing">{t('admin.tenants.subTrialing')}</option>
                        <option value="active">{t('admin.tenants.subActive')}</option>
                        <option value="past_due">{t('admin.tenants.subPastDue')}</option>
                        <option value="canceled">{t('admin.tenants.subCanceled')}</option>
                        <option value="expired">{t('admin.tenants.subExpired')}</option>
                        <option value="none">{t('admin.tenants.subNone')}</option>
                    </select>
                    <select
                        value={sort}
                        onChange={(e) => applyFilter({ sort: e.target.value })}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[var(--accent)]"
                    >
                        {SORT_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {t(`admin.tenants.sorts.${option.key}`)}
                            </option>
                        ))}
                    </select>
                    <label className="flex items-center gap-2 text-sm text-gray-600">
                        <input
                            type="checkbox"
                            checked={trashed}
                            onChange={(e) => applyFilter({ trashed: e.target.checked })}
                            className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]"
                        />
                        {t('admin.tenants.includeDeleted')}
                    </label>
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100">
                        <thead>
                            <tr className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                <th scope="col" className="px-4 py-2.5">{t('admin.tenants.thTenant')}</th>
                                <th scope="col" className="px-4 py-2.5">{t('admin.tenants.thStatus')}</th>
                                <th scope="col" className="px-4 py-2.5">{t('admin.tenants.thPlan')}</th>
                                <th scope="col" className="px-4 py-2.5">{t('admin.tenants.thRenews')}</th>
                                <th scope="col" className="px-4 py-2.5">{t('admin.tenants.thUsers')}</th>
                                <th scope="col" className="px-4 py-2.5">{t('admin.tenants.thCreated')}</th>
                                <th scope="col" className="px-4 py-2.5 text-right">{t('admin.common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {loading ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-14 text-center">
                                        <div className="flex justify-center">
                                            <ContentSkeleton size="sm" />
                                        </div>
                                    </td>
                                </tr>
                            ) : tenants.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-14 text-center text-sm text-gray-400">
                                        {t('admin.tenants.noMatches')}
                                    </td>
                                </tr>
                            ) : (
                                tenants.map((tenant) => (
                                    <tr key={tenant.id} className="transition hover:bg-gray-50/50">
                                        <td className="px-4 py-3">
                                            <Link
                                                to={`/tenants/${tenant.id}`}
                                                className="flex items-center gap-2 font-medium text-gray-800 hover:text-[var(--accent)]"
                                            >
                                                {tenant.name}
                                                <Badge>{tenant.slug}</Badge>
                                            </Link>
                                            {tenant.description && (
                                                <p className="mt-0.5 truncate text-xs text-gray-400">{tenant.description}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={tenant.deleted_at ? 'deactivated' : tenant.status} />
                                        </td>
                                        <td className="px-4 py-3">
                                            {tenant.plan_name ? (
                                                <div className="flex items-center gap-1.5">
                                                    <Badge>{tenant.plan_slug}</Badge>
                                                    <span className="text-xs capitalize text-gray-400">
                                                        {tenant.subscription_status?.replace('_', ' ')}
                                                    </span>
                                                </div>
                                            ) : (
                                                <span className="text-sm text-gray-400">—</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-600">
                                            {renewalLabel(t, tenant)}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-600">{tenant.users_count}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">
                                            <Timestamp date={tenant.created_at} />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <TenantActions
                                                tenant={tenant}
                                                onChanged={notify}
                                                onImpersonate={(user) => setImpersonateTarget({ tenant, user })}
                                            />
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {!loading && pagination.last_page > 1 && (
                    <Pagination
                        placement="sides"
                        variant="secondary"
                        size="sm"
                        page={pagination.current_page}
                        pages={pagination.last_page}
                        total={pagination.total}
                        label={t('admin.tenants.paginationLabel', { total: pagination.total, page: pagination.current_page, last: pagination.last_page })}
                        className="border-t border-gray-100 px-4 py-3"
                        onChange={setPage}
                    />
                )}
            </Card>
            <ImpersonateModal
                target={impersonateTarget}
                onCancel={() => setImpersonateTarget(null)}
                onConfirm={impersonate}
            />
        </div>
    );
}