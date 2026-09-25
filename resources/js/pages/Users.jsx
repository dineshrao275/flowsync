import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';

export default function Users() {
    usePageTitle('Users');
    const { can } = useAuth();
    const toast = useToast();
    const [users, setUsers] = useState([]);
    const [roles, setRoles] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(null);
    const [saving, setSaving] = useState(false);
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', roles: [] });
    const [formErrors, setFormErrors] = useState({});
    const manageable = can('users.manage');

    async function load() {
        try {
            const [{ data: usersData }, { data: rolesData }] = await Promise.all([
                api.get('/users'),
                api.get('/roles'),
            ]);
            setUsers(usersData.users);
            setRoles(rolesData.roles);
        } catch {
            setError('Unable to load users.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function saveRoles(user) {
        setSaving(true);
        try {
            const { data } = await api.put(`/users/${user.id}/roles`, {
                roles: user.roles,
            });
            setUsers((current) =>
                current.map((u) => (u.id === user.id ? { ...u, ...data.user } : u)),
            );
            toast.success(`Roles updated for ${user.name}.`);
            setEditing(null);
        } catch (e) {
            setError(fieldErrors(e).form || 'Failed to update roles.');
        } finally {
            setSaving(false);
        }
    }

    async function createUser(e) {
        e.preventDefault();
        setSaving(true);
        setFormErrors({});
        try {
            const { data } = await api.post('/users', form);
            setUsers((current) => [...current, data.user]);
            setCreating(false);
            setForm({ name: '', email: '', password: '', password_confirmation: '', roles: [] });
            toast.success(`${data.user.name} added to this tenant.`);
        } catch (e) {
            setFormErrors(fieldErrors(e));
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

    const roleOptions = roles.map((role) => ({ label: role.name, value: role.slug }));

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">Users</h2>
                    <p className="mt-1 text-sm text-gray-500">Accounts within your tenant.</p>
                </div>
                {manageable && (
                    <Button onClick={() => setCreating((open) => !open)}>Add user</Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {creating && (
                <Card title="Add user" subtitle="Accounts can be created by tenant admins.">
                    <form onSubmit={createUser} className="space-y-4">
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Input
                                label="Full name"
                                name="name"
                                placeholder="Jane Doe"
                                value={form.name}
                                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                                error={formErrors.name}
                                required
                            />
                            <Input
                                label="Email address"
                                name="email"
                                type="email"
                                placeholder="jane@company.com"
                                value={form.email}
                                onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
                                error={formErrors.email}
                                required
                            />
                            <Input
                                label="Password"
                                name="password"
                                type="password"
                                placeholder="At least 8 characters"
                                value={form.password}
                                onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
                                error={formErrors.password}
                                required
                            />
                            <Input
                                label="Confirm password"
                                name="password_confirmation"
                                type="password"
                                placeholder="Repeat password"
                                value={form.password_confirmation}
                                onChange={(e) => setForm((f) => ({ ...f, password_confirmation: e.target.value }))}
                                error={formErrors.password_confirmation}
                                required
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">Roles</label>
                            <select
                                multiple
                                value={form.roles}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        roles: Array.from(e.target.selectedOptions, (o) => o.value),
                                    }))
                                }
                                className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                            >
                                {roleOptions.map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {r.label}
                                    </option>
                                ))}
                            </select>
                            {formErrors.roles && <p className="mt-1.5 text-sm text-red-600">{formErrors.roles}</p>}
                        </div>
                        <div className="flex gap-2 pt-1">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={saving}>
                                Add user
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            <Table>
                <thead>
                    <tr>
                        <Th>Name</Th>
                        <Th>Email</Th>
                        <Th>Roles</Th>
                        <Th align="right">Actions</Th>
                    </tr>
                </thead>
                <tbody>
                    {users.length === 0 ? (
                        <TableEmpty colSpan={4}>No users yet.</TableEmpty>
                    ) : (
                        users.map((user, index) => (
                            <tr
                                key={user.id}
                                className="animate-fade-in transition-colors duration-150 hover:bg-gray-50"
                                style={{ animationDelay: `${index * 30}ms` }}
                            >
                                <Td>
                                    <div className="flex items-center gap-3">
                                        <span
                                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white"
                                            style={{ backgroundColor: 'var(--accent)' }}
                                        >
                                            {user.name.charAt(0).toUpperCase()}
                                        </span>
                                        <span className="font-medium text-gray-800">{user.name}</span>
                                    </div>
                                </Td>
                                <Td>
                                    <span className="text-gray-500">{user.email}</span>
                                </Td>
                                <Td>
                                    <div className="flex flex-wrap gap-1">
                                        {editing === user.id ? (
                                            <select
                                                multiple
                                                value={user.roles}
                                                onChange={(e) => {
                                                    const selected = Array.from(
                                                        e.target.selectedOptions,
                                                        (o) => o.value,
                                                    );
                                                    setUsers((current) =>
                                                        current.map((u) =>
                                                            u.id === user.id ? { ...u, roles: selected } : u,
                                                        ),
                                                    );
                                                }}
                                                className="rounded border border-gray-300 text-xs"
                                            >
                                                {roleOptions.map((r) => (
                                                    <option key={r.value} value={r.value}>
                                                        {r.label}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            user.roles.map((role) => <Badge key={role}>{role}</Badge>)
                                        )}
                                    </div>
                                </Td>
                                <Td align="right">
                                    {manageable &&
                                        (editing === user.id ? (
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() => setEditing(null)}
                                                >
                                                    Cancel
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    loading={saving}
                                                    onClick={() => saveRoles(user)}
                                                >
                                                    Save
                                                </Button>
                                            </div>
                                        ) : (
                                            <button
                                                onClick={() => setEditing(user.id)}
                                                className="rounded-lg px-3 py-1.5 text-xs font-medium text-white transition-all duration-150 hover:-translate-y-px hover:shadow-md active:scale-95"
                                                style={{ backgroundColor: 'var(--accent)' }}
                                            >
                                                Edit roles
                                            </button>
                                        ))}
                                </Td>
                            </tr>
                        ))
                    )}
                </tbody>
            </Table>
        </div>
    );
}