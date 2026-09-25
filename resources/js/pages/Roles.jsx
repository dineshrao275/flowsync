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

export default function Roles() {
    usePageTitle('Roles & Permissions');
    const { can } = useAuth();
    const toast = useToast();
    const [roles, setRoles] = useState([]);
    const [permissions, setPermissions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [editingId, setEditingId] = useState(null);
    const [savingId, setSavingId] = useState(null);
    const [creating, setCreating] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', slug: '', permissions: [] });
    const [createErrors, setCreateErrors] = useState({});

    async function load() {
        try {
            const { data } = await api.get('/roles');
            setRoles(data.roles);
            setPermissions(data.permissions);
        } catch {
            setError('Unable to load roles.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function createRole(e) {
        e.preventDefault();
        setSavingId('new');
        setCreateErrors({});
        try {
            const { data } = await api.post('/roles', createForm);
            setRoles((current) => [...current, data.role]);
            setCreating(false);
            setCreateForm({ name: '', slug: '', permissions: [] });
            toast.success(`Role "${data.role.name}" created.`);
        } catch (e) {
            setCreateErrors(fieldErrors(e));
        } finally {
            setSavingId(null);
        }
    }

    function togglePermission(roleId, permissionId) {
        setRoles((current) =>
            current.map((role) => {
                if (role.id !== roleId) return role;
                const has = role.permissions.some((p) => p.id === permissionId);
                return {
                    ...role,
                    permissions: has
                        ? role.permissions.filter((p) => p.id !== permissionId)
                        : [
                              ...role.permissions,
                              { id: permissionId },
                          ],
                };
            }),
        );

        if (!can('roles.manage')) return;
        setEditingId((current) => current ?? roleId);
    }

    async function save(role) {
        setSavingId(role.id);
        try {
            const { data } = await api.put(`/roles/${role.id}`, {
                name: role.name,
                permissions: role.permissions.map((p) => p.id),
            });
            setRoles((current) =>
                current.map((r) => (r.id === role.id ? { ...r, ...data.role } : r)),
            );
            toast.success(`Role "${role.name}" updated.`);
            setEditingId(null);
        } catch (e) {
            setError(fieldErrors(e).form || 'Failed to save role.');
        } finally {
            setSavingId(null);
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    const manageable = can('roles.manage');

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">Roles &amp; Permissions</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Control what each role can do across the admin panel.
                        {!manageable && ' You have view-only access.'}
                    </p>
                </div>
                {manageable && (
                    <Button onClick={() => setCreating((open) => !open)}>New role</Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {creating && (
                <Card title="Create role" subtitle="Roles apply only within this tenant.">
                    <form onSubmit={createRole} className="space-y-4">
                        {createErrors.form && <Alert>{createErrors.form}</Alert>}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Input
                                label="Role name"
                                name="name"
                                placeholder="Manager"
                                value={createForm.name}
                                onChange={(e) => setCreateForm((f) => ({ ...f, name: e.target.value }))}
                                error={createErrors.name}
                                required
                            />
                            <Input
                                label="Slug"
                                name="slug"
                                placeholder="manager"
                                value={createForm.slug}
                                onChange={(e) => setCreateForm((f) => ({ ...f, slug: e.target.value }))}
                                error={createErrors.slug}
                                required
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">Permissions</label>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {permissions.map((permission) => (
                                    <label
                                        key={permission.id}
                                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm transition hover:bg-gray-50"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={createForm.permissions.includes(permission.id)}
                                            onChange={() =>
                                                setCreateForm((f) => ({
                                                    ...f,
                                                    permissions: f.permissions.includes(permission.id)
                                                        ? f.permissions.filter((id) => id !== permission.id)
                                                        : [...f.permissions, permission.id],
                                                }))
                                            }
                                            className="h-4 w-4 rounded border-gray-300 text-[var(--accent)] accent-[var(--accent)] focus:ring-[var(--accent-ring)]"
                                        />
                                        <span>{permission.name}</span>
                                    </label>
                                ))}
                            </div>
                            {createErrors.permissions && (
                                <p className="mt-1.5 text-sm text-red-600">{createErrors.permissions}</p>
                            )}
                        </div>
                        <div className="flex gap-2 pt-1">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={savingId === 'new'}>
                                Create role
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            <Table>
                <thead>
                    <tr>
                        <Th>Role</Th>
                        <Th>Slug</Th>
                        <Th>Permissions</Th>
                        <Th align="right">Members</Th>
                        <Th align="right">Actions</Th>
                    </tr>
                </thead>
                <tbody>
                    {roles.length === 0 ? (
                        <TableEmpty colSpan={5}>No roles yet.</TableEmpty>
                    ) : (
                        roles.map((role, index) => {
                            const selectedIds = role.permissions?.map((p) => p.id) || [];
                            const editing = manageable && editingId === role.id;
                            const granted = permissions
                                .filter((permission) => selectedIds.includes(permission.id))
                                .map((permission) => `${permission.name} (${permission.slug})`);

                            return (
                                <tr
                                    key={role.id}
                                    className="animate-fade-in align-top transition-colors duration-150 hover:bg-gray-50"
                                    style={{ animationDelay: `${index * 30}ms` }}
                                >
                                    <Td>
                                        <span className="block font-medium capitalize">
                                            {editingId === role.id ? (
                                                <input
                                                    value={role.name}
                                                    onChange={(e) =>
                                                        setRoles((current) =>
                                                            current.map((r) =>
                                                                r.id === role.id ? { ...r, name: e.target.value } : r,
                                                            ),
                                                        )
                                                    }
                                                    className="rounded border border-gray-300 px-2 py-1 text-sm capitalize"
                                                />
                                            ) : (
                                                role.name
                                            )}
                                        </span>
                                    </Td>
                                    <Td>
                                        <Badge>{role.slug}</Badge>
                                    </Td>
                                    <Td>
                                        {editing ? (
                                            <ul className="grid grid-cols-1 gap-1 sm:grid-cols-2 xl:grid-cols-3">
                                                {permissions.map((permission) => {
                                                    const checked = selectedIds.includes(permission.id);
                                                    return (
                                                        <li key={permission.id}>
                                                            <label
                                                                className={`flex cursor-pointer items-start gap-2 rounded-lg px-2 py-1.5 text-sm transition ${
                                                                    manageable ? 'hover:bg-gray-50' : 'cursor-default'
                                                                }`}
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    checked={checked}
                                                                    disabled={!manageable}
                                                                    onChange={() => togglePermission(role.id, permission.id)}
                                                                    className="mt-0.5 h-4 w-4 rounded border-gray-300 text-[var(--accent)] accent-[var(--accent)] transition-all duration-150 focus:ring-[var(--accent-ring)] disabled:cursor-not-allowed disabled:opacity-50"
                                                                />
                                                                <span className="text-gray-700">
                                                                    {permission.name}
                                                                    <span className="block text-xs text-gray-400">
                                                                        {permission.slug}
                                                                    </span>
                                                                </span>
                                                            </label>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        ) : (
                                            <span className="text-sm text-gray-500">
                                                {granted.length ? granted.join(', ') : 'No permissions'}
                                            </span>
                                        )}
                                    </Td>
                                    <Td align="right" className="tabular-nums">
                                        {role.users_count}
                                    </Td>
                                    <Td align="right">
                                        {manageable &&
                                            (editingId === role.id ? (
                                                <div className="flex justify-end gap-2">
                                                    <Button
                                                        variant="secondary"
                                                        size="sm"
                                                        onClick={() => setEditingId(null)}
                                                    >
                                                        Cancel
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        loading={savingId === role.id}
                                                        onClick={() => save(role)}
                                                    >
                                                        Save role
                                                    </Button>
                                                </div>
                                            ) : (
                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() => setEditingId(role.id)}
                                                >
                                                    Edit permissions
                                                </Button>
                                            ))}
                                    </Td>
                                </tr>
                            );
                        })
                    )}
                </tbody>
            </Table>
        </div>
    );
}