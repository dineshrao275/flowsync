import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useClickOutside } from '../hooks/useClickOutside';

function ImpersonateMenu({ tenant, onClose, onPick }) {
    const ref = useRef(null);
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useClickOutside(ref, onClose);

    useEffect(() => {
        api.get(`/tenants/${tenant.id}/users`)
            .then(({ data }) => setUsers(data.users))
            .catch(() => setError('Unable to load users.'))
            .finally(() => setLoading(false));
    }, [tenant.id]);

    return (
        <div
            ref={ref}
            className="absolute right-0 top-full mt-3 z-10 w-72 origin-top-right animate-scale-in overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl"
        >
            <div className="border-b border-gray-100 px-4 py-3">
                <p className="text-sm font-semibold text-gray-900">View as a user</p>
                <p className="text-xs text-gray-500">Choose someone from {tenant.name} to impersonate.</p>
            </div>
            <div className="max-h-72 overflow-y-auto">
                {loading ? (
                    <div className="flex justify-center py-6">
                        <Spinner />
                    </div>
                ) : error ? (
                    <p className="px-4 py-6 text-center text-sm text-red-500">{error}</p>
                ) : (
                    users.map((user) => (
                        <button
                            key={user.id}
                            onClick={() => onPick(user)}
                            className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm transition hover:bg-gray-50"
                        >
                            <span
                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white"
                                style={{ backgroundColor: 'var(--accent)' }}
                            >
                                {user.name.charAt(0).toUpperCase()}
                            </span>
                            <span className="min-w-0">
                                <span className="block truncate font-medium text-gray-800">{user.name}</span>
                                <span className="block truncate text-xs text-gray-500">{user.email}</span>
                                <span className="mt-0.5 flex flex-wrap gap-1">
                                    {user.roles.map((role) => (
                                        <Badge key={role}>{role}</Badge>
                                    ))}
                                </span>
                            </span>
                        </button>
                    ))
                )}
            </div>
        </div>
    );
}

export default function Tenants() {
    const { refresh } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const [tenants, setTenants] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [openMenu, setOpenMenu] = useState(null);
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ name: '', slug: '', description: '' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        try {
            const { data } = await api.get('/tenants');
            setTenants(data.tenants);
        } catch {
            setError('Unable to load tenants.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function impersonate(user) {
        try {
            await api.post('/impersonate', { user_id: user.id });
            await refresh();
            toast.success(`Viewing panel as ${user.name}.`);
            navigate('/dashboard', { replace: true });
        } catch (e) {
            setError(fieldErrors(e).form || 'Failed to impersonate.');
        }
    }

    async function createTenant(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await api.post('/tenants', form);
            setCreating(false);
            setForm({ name: '', slug: '', description: '' });
            toast.success(`Tenant "${form.name}" created and provisioned.`);
            await load();
        } catch (e) {
            setErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
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

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {tenants.map((tenant, index) => (
                    <div
                        key={tenant.id}
                        className="animate-fade-in-up"
                        style={{ animationDelay: `${index * 60}ms` }}
                    >
                        <Card
                            className="h-full"
                            title={
                                <span className="flex items-center gap-2">
                                    {tenant.name}
                                    <Badge>{tenant.slug}</Badge>
                                </span>
                            }
                            subtitle={tenant.description || 'No description'}
                            actions={
                                <div className="relative">
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => setOpenMenu((current) => (current === tenant.id ? null : tenant.id))}
                                    >
                                        View as user
                                    </Button>
                                    {openMenu === tenant.id && (
                                        <ImpersonateMenu
                                            tenant={tenant}
                                            onClose={() => setOpenMenu(null)}
                                            onPick={impersonate}
                                        />
                                    )}
                                </div>
                            }
                        >
                            <div className="grid grid-cols-2 gap-4">
                                <div className="rounded-lg bg-gray-50 p-4 text-center">
                                    <p className="text-2xl font-bold text-gray-900">{tenant.users_count}</p>
                                    <p className="text-xs text-gray-500">Users</p>
                                </div>
                                <div className="rounded-lg bg-gray-50 p-4 text-center">
                                    <p className="text-2xl font-bold text-gray-900">{tenant.roles_count}</p>
                                    <p className="text-xs text-gray-500">Roles</p>
                                </div>
                            </div>
                            <p className="mt-4 text-xs text-gray-400">
                                Created {new Date(tenant.created_at).toLocaleDateString()} &middot; fully tenant-isolated
                            </p>
                        </Card>
                    </div>
                ))}
            </div>
        </div>
    );
}