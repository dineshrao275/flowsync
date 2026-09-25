import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Pagination from '../components/ui/Pagination';
import Input from '../components/ui/Input';
import { useAuth } from '../context/AuthContext';
import LimitsEditor from '../components/billing/LimitsEditor';
import { useToast } from '../context/ToastContext';
import { useClickOutside } from '../hooks/useClickOutside';
import usePageTitle from '../hooks/usePageTitle';

const STATUS_OPTIONS = [
    { value: 'pending', label: 'Pending' },
    { value: 'provisioning', label: 'Provisioning' },
    { value: 'trial', label: 'Trial' },
    { value: 'active', label: 'Active' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'expired', label: 'Expired' },
    { value: 'deactivated', label: 'Deactivated' },
    { value: 'provisioning_failed', label: 'Provisioning failed' },
];

const STATUS_STYLES = {
    active: 'bg-emerald-100 text-emerald-700',
    trial: 'bg-sky-100 text-sky-700',
    suspended: 'bg-amber-100 text-amber-700',
    expired: 'bg-rose-100 text-rose-700',
    deactivated: 'bg-gray-200 text-gray-600',
    pending: 'bg-gray-100 text-gray-600',
    provisioning: 'bg-indigo-100 text-[var(--accent)]',
    provisioning_failed: 'bg-rose-100 text-rose-700',
};

const SORT_OPTIONS = [
    { value: 'name', label: 'Name (A–Z)' },
    { value: '-name', label: 'Name (Z–A)' },
    { value: '-created_at', label: 'Newest' },
    { value: 'created_at', label: 'Oldest' },
    { value: '-users_count', label: 'Most users' },
    { value: '-updated_at', label: 'Recently updated' },
];

function parseSort(value) {
    const dir = value.startsWith('-') ? 'desc' : 'asc';
    return { sort: value.replace(/^-/, ''), dir };
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

function TenantActions({ tenant, onEdited, onChanged, onImpersonate }) {
    const ref = useRef(null);
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState('menu');
    const [users, setUsers] = useState([]);
    const [loadingUsers, setLoadingUsers] = useState(false);
    const [busy, setBusy] = useState(false);

    useClickOutside(ref, () => setOpen(false));

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
        setBusy(true);
        try {
            const action = tenant.status === 'suspended' ? 'activate' : 'suspend';
            await api.post(`/tenants/${tenant.id}/${action}`);
            onChanged(`Tenant ${action}${tenant.status === 'suspended' ? 'd' : ''}.`);
        } catch (e) {
            onChanged(fieldErrors(e).form || `Unable to ${tenant.status === 'suspended' ? 'activate' : 'suspend'} tenant.`, true);
            setOpen(false);
        } finally {
            setBusy(false);
            setOpen(false);
        }
    }

    async function remove() {
        if (!window.confirm(`Delete tenant "${tenant.name}"? It can be restored later.`)) return;
        setBusy(true);
        try {
            await api.delete(`/tenants/${tenant.id}`);
            onChanged('Tenant deleted.');
        } catch (e) {
            onChanged(fieldErrors(e).form || 'Unable to delete tenant.', true);
        } finally {
            setBusy(false);
            setOpen(false);
        }
    }

    async function restore() {
        setBusy(true);
        try {
            await api.post(`/tenants/${tenant.id}/restore`);
            onChanged('Tenant restored.');
        } catch (e) {
            onChanged(fieldErrors(e).form || 'Unable to restore tenant.', true);
        } finally {
            setBusy(false);
            setOpen(false);
        }
    }

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                aria-label="Tenant actions"
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
                <div className="absolute right-0 top-full z-10 mt-1 w-52 origin-top-right animate-scale-in overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl">
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
                                Back
                            </button>
                            <p className="border-b border-gray-100 px-4 py-2 text-xs font-medium text-gray-400">
                                View as a user
                            </p>
                            <div className="max-h-64 overflow-y-auto">
                                {loadingUsers ? (
                                    <p className="px-4 py-3 text-sm text-gray-400">Loading…</p>
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
                                    <ActionLink to={`/tenants/${tenant.id}`} onClick={() => setOpen(false)}>
                                        View details
                                    </ActionLink>
                                    <ActionButton onClick={() => { setOpen(false); onEdited(tenant); }}>
                                        Edit
                                    </ActionButton>
                                    <ActionButton onClick={loadUsers}>
                                        View as user…
                                    </ActionButton>
                                    <div className="my-1 border-t border-gray-100" />
                                    <ActionButton disabled={busy} onClick={toggleSuspend}>
                                        {tenant.status === 'suspended' ? 'Enable (activate)' : 'Disable (suspend)'}
                                    </ActionButton>
                                    <div className="my-1 border-t border-gray-100" />
                                    <ActionButton disabled={busy} onClick={remove} danger>
                                        Delete
                                    </ActionButton>
                                </>
                            )}
                            {trashed && (
                                <>
                                    <ActionButton disabled={busy} onClick={restore}>
                                        Restore
                                    </ActionButton>
                                    <div className="my-1 border-t border-gray-100" />
                                    <ActionButton disabled={busy} onClick={remove} danger>
                                        Delete
                                    </ActionButton>
                                </>
                            )}
                        </>
                    )}
                </div>
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
    usePageTitle('Tenants');
    const { refresh } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const [tenants, setTenants] = useState([]);
    const [plans, setPlans] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({ q: '', status: '', plan_id: '', sort: 'name', trashed: false });
    const [page, setPage] = useState(1);
    const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ name: '', slug: '', description: '', plan_id: '', trial_days: '' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [editing, setEditing] = useState(null);
    const [editForm, setEditForm] = useState({ name: '', slug: '', description: '', limits_override: null });
    const [editErrors, setEditErrors] = useState({});
    const [editSaving, setEditSaving] = useState(false);

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

    async function createTenant(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await api.post('/tenants', form);
            setCreating(false);
            setForm({ name: '', slug: '', description: '', plan_id: '', trial_days: '' });
            toast.success(`Tenant "${form.name}" created and provisioned.`);
            await load();
        } catch (e) {
            setErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    function startEdit(tenant) {
        setEditing(tenant);
        setEditForm({
            name: tenant.name,
            slug: tenant.slug,
            description: tenant.description || '',
            limits_override: tenant.limits_override || null,
        });
        setEditErrors({});
    }

    async function saveEdit(e) {
        e.preventDefault();
        setEditSaving(true);
        setEditErrors({});
        try {
            await api.put(`/tenants/${editing.id}`, editForm);
            setEditing(null);
            toast.success('Tenant updated.');
            await load();
        } catch (e) {
            setEditErrors(fieldErrors(e));
        } finally {
            setEditSaving(false);
        }
    }

    const { q, status, plan_id, sort, trashed } = filters;

    function applyFilter(patch) {
        setFilters((f) => ({ ...f, ...patch }));
        setPage(1);
    }

    async function impersonate(tenant, user) {
        try {
            await api.post('/impersonate', { user_id: user.id, tenant_id: tenant.id });
            await refresh();
            toast.success(`Viewing panel as ${user.name}.`);
            navigate('/dashboard', { replace: true });
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Failed to impersonate.');
        }
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">Tenants</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Every tenant is fully isolated with its own users, roles and permissions.
                    </p>
                </div>
                <Button size="md" onClick={() => setCreating((open) => !open)}>
                    New tenant
                </Button>
            </div>

            {error && <Alert>{error}</Alert>}

            {creating && (
                <Card title="Create tenant" subtitle="Users, roles and permissions are provisioned automatically.">
                    <form onSubmit={createTenant} className="space-y-4">
                        <Input
                            label="Tenant name"
                            name="name"
                            placeholder="Acme Corp"
                            value={form.name}
                            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                            error={errors.name}
                            required
                        />
                        <Input
                            label="Slug"
                            name="slug"
                            placeholder="acme"
                            value={form.slug}
                            onChange={(e) => setForm((f) => ({ ...f, slug: e.target.value }))}
                            error={errors.slug}
                            required
                        />
                        <Input
                            label="Description (optional)"
                            name="description"
                            placeholder="What this tenant does"
                            value={form.description}
                            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                            error={errors.description}
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <label className="block text-sm font-medium text-gray-700">
                                Plan
                                <select
                                    name="plan_id"
                                    value={form.plan_id}
                                    onChange={(e) => setForm((f) => ({ ...f, plan_id: e.target.value }))}
                                    className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                                >
                                    <option value="">No subscription</option>
                                    {plans.map((plan) => (
                                        <option key={plan.id} value={plan.id}>
                                            {plan.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.plan_id && <span className="mt-1 block text-xs text-red-500">{errors.plan_id}</span>}
                            </label>
                            <Input
                                label="Trial days (optional)"
                                type="number"
                                name="trial_days"
                                placeholder="e.g. 14"
                                value={form.trial_days}
                                onChange={(e) => setForm((f) => ({ ...f, trial_days: e.target.value }))}
                                error={errors.trial_days}
                            />
                        </div>
                        <div className="flex gap-2 pt-1">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={saving}>
                                Create tenant
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            {editing && (
                <Card title={`Edit ${editing.name}`} subtitle="Rename the tenant, adjust its slug, or cap its resources.">
                    <form onSubmit={saveEdit} className="space-y-4">
                        <Input
                            label="Tenant name"
                            name="name"
                            value={editForm.name}
                            onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                            error={editErrors.name}
                            required
                        />
                        <Input
                            label="Slug"
                            name="slug"
                            value={editForm.slug}
                            onChange={(e) => setEditForm((f) => ({ ...f, slug: e.target.value }))}
                            error={editErrors.slug}
                            required
                        />
                        <Input
                            label="Description (optional)"
                            name="description"
                            value={editForm.description}
                            onChange={(e) => setEditForm((f) => ({ ...f, description: e.target.value }))}
                            error={editErrors.description}
                        />
                        <LimitsEditor
                            key={editing.id}
                            limits={editForm.limits_override}
                            errors={editErrors}
                            legend="Resource limits override"
                            hint="Blank = inherit the plan limit. Anything set here wins over the tenant's plan."
                            onChange={(next) => setEditForm((f) => ({ ...f, limits_override: next }))}
                        />
                        <div className="flex gap-2 pt-1">
                            <Button type="button" variant="secondary" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={editSaving}>
                                Save
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            <Card className="p-0">
                <div className="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-3">
                    <input
                        type="search"
                        placeholder="Search name, slug, description…"
                        value={q}
                        onChange={(e) => applyFilter({ q: e.target.value })}
                        className="min-w-48 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                    />
                    <select
                        value={status}
                        onChange={(e) => applyFilter({ status: e.target.value })}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                    >
                        <option value="">All statuses</option>
                        {STATUS_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <select
                        value={plan_id}
                        onChange={(e) => applyFilter({ plan_id: e.target.value })}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                    >
                        <option value="">All plans</option>
                        {plans.map((plan) => (
                            <option key={plan.id} value={plan.id}>
                                {plan.name}
                            </option>
                        ))}
                    </select>
                    <select
                        value={sort}
                        onChange={(e) => applyFilter({ sort: e.target.value })}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                    >
                        {SORT_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <label className="flex items-center gap-2 text-sm text-gray-600">
                        <input
                            type="checkbox"
                            checked={trashed}
                            onChange={(e) => applyFilter({ trashed: e.target.checked })}
                            className="h-4 w-4 rounded border-gray-300 text-[var(--accent)] focus:ring-[var(--accent-ring)]"
                        />
                        Include deleted
                    </label>
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100">
                        <thead>
                            <tr className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                <th className="px-4 py-2.5">Tenant</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5">Plan</th>
                                <th className="px-4 py-2.5">Users</th>
                                <th className="px-4 py-2.5">Created</th>
                                <th className="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {loading ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-14 text-center">
                                        <div className="flex justify-center">
                                            <Spinner />
                                        </div>
                                    </td>
                                </tr>
                            ) : tenants.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-14 text-center text-sm text-gray-400">
                                        No tenants match these filters.
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
                                        <td className="px-4 py-3 text-sm text-gray-600">{tenant.users_count}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">
                                            {new Date(tenant.created_at).toLocaleDateString()}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <TenantActions
                                                tenant={tenant}
                                                onEdited={startEdit}
                                                onChanged={notify}
                                                onImpersonate={(user) => impersonate(tenant, user)}
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
                        label={`${pagination.total} tenants · page ${pagination.current_page} of ${pagination.last_page}`}
                        className="border-t border-gray-100 px-4 py-3"
                        onChange={setPage}
                    />
                )}
            </Card>
        </div>
    );
}