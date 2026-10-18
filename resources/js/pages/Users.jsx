import { useEffect, useState } from 'react';
import Avatar from '../components/ui/Avatar';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import usePageTitle from '../hooks/usePageTitle';

export default function Users() {
    const { t } = useTranslation();
    usePageTitle(t('admin.users.pageTitle'));
    const navigate = useNavigate();
    const { can, user: currentUser } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const [users, setUsers] = useState([]);
    const [roles, setRoles] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
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
        } catch (err) {
            if (err?.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }
            setError(t('admin.users.loadFailed'));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function makeDefault(user) {
        setSaving(true);
        try {
            const { data } = await api.put(`/users/${user.id}/default`);
            setUsers((current) =>
                current.map((u) => ({ ...u, is_default: u.id === user.id })),
            );
            toast.success(data.message);
        } catch (e) {
            setError(fieldErrors(e).form || t('admin.users.defaultFailed'));
        } finally {
            setSaving(false);
        }
    }

    async function deleteUser(user) {
        if (!(await confirm({ title: t('admin.users.deleteTitle'), message: t('admin.users.deleteMessage', { name: user.name }), variant: 'danger' }))) return;

        setSaving(true);
        try {
            await api.delete(`/users/${user.id}`);
            setUsers((current) => current.filter((u) => u.id !== user.id));
            toast.success(t('admin.users.deleted', { name: user.name }));
        } catch (e) {
            setError(fieldErrors(e).form || t('admin.users.deleteFailed'));
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
            toast.success(t('admin.users.added', { name: data.user.name }));
        } catch (e) {
            setFormErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <ContentSkeleton size="lg" />
        );
    }

    const roleOptions = roles.map((role) => ({ label: role.name, value: role.slug }));

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('admin.users.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        {t('admin.users.subtitle')}
                    </p>
                </div>
                {manageable && (
                    <div className="flex gap-2">
                        <Button variant="secondary" onClick={() => navigate('/users/import')}>{t('admin.users.importCsv')}</Button>
                        <Button onClick={() => setCreating((open) => !open)}>{t('admin.users.addUser')}</Button>
                    </div>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {creating && (
                <Card title={t('admin.users.addTitle')} subtitle={t('admin.users.addSubtitle')}>
                    <form onSubmit={createUser} className="space-y-4">
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Input
                                label={t('admin.users.fullName')}
                                name="name"
                                placeholder={t('admin.users.fullNamePlaceholder')}
                                value={form.name}
                                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                                error={formErrors.name}
                                required
                            />
                            <Input
                                label={t('admin.users.emailAddress')}
                                name="email"
                                type="email"
                                placeholder={t('admin.users.emailPlaceholder')}
                                value={form.email}
                                onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
                                error={formErrors.email}
                                required
                            />
                            <Input
                                label={t('admin.users.password')}
                                name="password"
                                type="password"
                                placeholder={t('admin.users.passwordPlaceholder')}
                                value={form.password}
                                onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
                                error={formErrors.password}
                                required
                            />
                            <Input
                                label={t('admin.users.confirmPassword')}
                                name="password_confirmation"
                                type="password"
                                placeholder={t('admin.users.confirmPasswordPlaceholder')}
                                value={form.password_confirmation}
                                onChange={(e) => setForm((f) => ({ ...f, password_confirmation: e.target.value }))}
                                error={formErrors.password_confirmation}
                                required
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.users.roles')}</label>
                            <select
                                multiple
                                value={form.roles}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        roles: Array.from(e.target.selectedOptions, (o) => o.value),
                                    }))
                                }
                                className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm transition focus:border-[var(--accent)] focus:outline-none"
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
                                {t('admin.common.cancel')}
                            </Button>
                            <Button type="submit" loading={saving}>
                                {t('admin.users.addUser')}
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            <Table>
                <thead>
                    <tr>
                        <Th>{t('admin.common.name')}</Th>
                        <Th>{t('admin.common.email')}</Th>
                        <Th>{t('admin.users.roles')}</Th>
                        <Th align="right">{t('admin.common.actions')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {users.length === 0 ? (
                        <TableEmpty colSpan={4}>{t('admin.users.empty')}</TableEmpty>
                    ) : (
                        users.map((user, index) => (
                            <tr
                                key={user.id}
                                className="animate-fade-in transition-colors duration-150 hover:bg-gray-50"
                                style={{ animationDelay: `${index * 30}ms` }}
                            >
                                <Td>
                                    <div className="flex items-center gap-3">
                                        <Avatar name={user.name} photoUrl={user.photo_url} />
                                        <span className="font-medium text-gray-800">{user.name}</span>
                                        {user.is_default && (
                                            <Badge tone="accent" title={t('admin.users.defaultBadgeTitle')}>
                                                {t('admin.users.defaultBadge')}
                                            </Badge>
                                        )}
                                    </div>
                                </Td>
                                <Td>
                                    <span className="text-gray-500">{user.email}</span>
                                </Td>
                                <Td>
                                    <div className="flex flex-wrap gap-1">
                                        {user.roles.map((role) => <Badge key={role}>{role}</Badge>)}
                                    </div>
                                </Td>
                                <Td align="right">
                                    {manageable && (
                                        <div className="flex items-center justify-end gap-2">
                                            <Button size="sm" variant="secondary" onClick={() => navigate(`/users/${user.id}`)}>
                                                {t('admin.common.edit')}
                                            </Button>
                                            {!user.is_default && (
                                                <Button
                                                    size="sm"
                                                    variant="subtle"
                                                    loading={saving}
                                                    // The default must be an admin; the server
                                                    // rejects anyone else with 422.
                                                    disabled={!user.roles?.includes('admin')}
                                                    title={
                                                        user.roles?.includes('admin')
                                                            ? t('admin.users.makeDefaultTitle')
                                                            : t('admin.users.onlyAdminDefault')
                                                    }
                                                    onClick={() => makeDefault(user)}
                                                >
                                                    {t('admin.users.makeDefault')}
                                                </Button>
                                            )}
                                            {user.id !== currentUser?.id && (
                                                <Button
                                                    size="sm"
                                                    variant="danger"
                                                    disabled={user.is_default}
                                                    title={
                                                        user.is_default
                                                            ? t('admin.users.defaultDeleteTitle')
                                                            : t('admin.users.deleteTooltip')
                                                    }
                                                    onClick={() => deleteUser(user)}
                                                >
                                                    {t('admin.common.delete')}
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </Td>
                            </tr>
                        ))
                    )}
                </tbody>
            </Table>
        </div>
    );
}