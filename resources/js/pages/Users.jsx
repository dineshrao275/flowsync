import { useCallback, useEffect, useState } from 'react';
import ListSavedFilters from '../components/ui/ListSavedFilters';
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
import PasswordInput from '../components/ui/PasswordInput';
import PasswordMatchHint from '../components/ui/PasswordMatchHint';
import MultiSelect from '../components/ui/MultiSelect';
import Pagination from '../components/ui/Pagination';
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
    const [pagination, setPagination] = useState(null);
    const [loading, setLoading] = useState(true);
    const [fetching, setFetching] = useState(false);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [creating, setCreating] = useState(false);

    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [selectedRole, setSelectedRole] = useState('');
    const [selectedType, setSelectedType] = useState('');
    const [page, setPage] = useState(1);

    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', roles: [] });
    const [formErrors, setFormErrors] = useState({});
    const manageable = can('users.manage');

    // Debounce search input
    useEffect(() => {
        const timer = setTimeout(() => {
            setDebouncedSearch(search);
            setPage(1);
        }, 250);
        return () => clearTimeout(timer);
    }, [search]);

    // Fetch roles on mount
    useEffect(() => {
        api.get('/roles')
            .then(({ data }) => setRoles(data.roles || []))
            .catch(() => {});
    }, []);

    const fetchUsers = useCallback(async (targetPage = page) => {
        setFetching(true);
        try {
            const params = {
                page: targetPage,
                per_page: 15,
            };
            if (debouncedSearch.trim()) params.q = debouncedSearch.trim();
            if (selectedRole) params.role = selectedRole;
            if (selectedType === 'default') params.is_default = 'true';
            if (selectedType === 'standard') params.is_default = 'false';

            const { data } = await api.get('/users', { params });
            setUsers(data.users || []);
            setPagination(data.pagination || {
                current_page: 1,
                last_page: 1,
                per_page: (data.users || []).length,
                total: (data.users || []).length,
            });
            setError(null);
        } catch (err) {
            if (err?.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }
            setError(t('admin.users.loadFailed'));
        } finally {
            setLoading(false);
            setFetching(false);
        }
    }, [debouncedSearch, selectedRole, selectedType, page, navigate, t]);

    useEffect(() => {
        fetchUsers();
    }, [fetchUsers]);

    async function makeDefault(user) {
        setSaving(true);
        try {
            const { data } = await api.put(`/users/${user.id}/default`);
            toast.success(data.message);
            fetchUsers(page);
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
            toast.success(t('admin.users.deleted', { name: user.name }));
            const nextPage = users.length === 1 && page > 1 ? page - 1 : page;
            if (nextPage !== page) {
                setPage(nextPage);
            } else {
                fetchUsers(page);
            }
        } catch (e) {
            setError(fieldErrors(e).form || t('admin.users.deleteFailed'));
        } finally {
            setSaving(false);
        }
    }

    function clearPasswordConfirmationError() {
        setFormErrors((current) => {
            if (!current.password_confirmation) return current;
            const next = { ...current };
            delete next.password_confirmation;
            return next;
        });
    }

    async function createUser(e) {
        e.preventDefault();
        if (form.password !== form.password_confirmation) {
            setFormErrors({ password_confirmation: t('ui.passwordsMismatch') });
            return;
        }
        setSaving(true);
        setFormErrors({});
        try {
            const { data } = await api.post('/users', form);
            setCreating(false);
            setForm({ name: '', email: '', password: '', password_confirmation: '', roles: [] });
            toast.success(t('admin.users.added', { name: data.user.name }));
            setPage(1);
            fetchUsers(1);
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

    const roleOptions = roles.map((role) => ({
        label: role.name,
        value: role.slug,
        description: role.description || (role.slug !== role.name ? role.slug : null),
    }));
    const hasActiveFilters = Boolean(search || selectedRole || selectedType);

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
                            <PasswordInput
                                label={t('admin.users.password')}
                                name="password"
                                placeholder={t('admin.users.passwordPlaceholder')}
                                value={form.password}
                                onChange={(e) => {
                                    setForm((f) => ({ ...f, password: e.target.value }));
                                    clearPasswordConfirmationError();
                                }}
                                error={formErrors.password}
                                required
                            />
                            <PasswordInput
                                label={t('admin.users.confirmPassword')}
                                name="password_confirmation"
                                placeholder={t('admin.users.confirmPasswordPlaceholder')}
                                value={form.password_confirmation}
                                onChange={(e) => {
                                    setForm((f) => ({ ...f, password_confirmation: e.target.value }));
                                    clearPasswordConfirmationError();
                                }}
                                error={formErrors.password_confirmation}
                                required
                            />
                            <div className="sm:col-span-2">
                                <PasswordMatchHint password={form.password} confirmation={form.password_confirmation} />
                            </div>
                        </div>
                        <MultiSelect
                            label={t('admin.users.roles')}
                            options={roleOptions}
                            value={form.roles}
                            onChange={(roles) => setForm((f) => ({ ...f, roles }))}
                            placeholder={t('admin.users.selectRoles', 'Select user roles…')}
                            searchPlaceholder={t('admin.users.searchRoles', 'Search roles…')}
                            error={formErrors.roles}
                            showQuickPills
                        />
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

            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200/80 bg-white p-3 shadow-xs">
                <div className="flex flex-1 flex-wrap items-center gap-2.5">
                    <div className="min-w-56 flex-1 sm:max-w-xs">
                        <input
                            type="search"
                            placeholder={t('admin.users.searchPlaceholder')}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-xs transition focus:border-[var(--accent)] focus:outline-none focus:ring-1 focus:ring-[var(--accent)]"
                        />
                    </div>
                    <select
                        aria-label={t('admin.users.allRoles')}
                        value={selectedRole}
                        onChange={(e) => {
                            setSelectedRole(e.target.value);
                            setPage(1);
                        }}
                        className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-xs transition focus:border-[var(--accent)] focus:outline-none focus:ring-1 focus:ring-[var(--accent)]"
                    >
                        <option value="">{t('admin.users.allRoles')}</option>
                        {roleOptions.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label={t('admin.users.allTypes')}
                        value={selectedType}
                        onChange={(e) => {
                            setSelectedType(e.target.value);
                            setPage(1);
                        }}
                        className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-xs transition focus:border-[var(--accent)] focus:outline-none focus:ring-1 focus:ring-[var(--accent)]"
                    >
                        <option value="">{t('admin.users.allTypes')}</option>
                        <option value="default">{t('admin.users.defaultOnly')}</option>
                        <option value="standard">{t('admin.users.standardOnly')}</option>
                    </select>
                    <ListSavedFilters
                        page="users"
                        value={{ q: search, role: selectedRole, type: selectedType }}
                        onApply={(saved) => {
                            setSearch(saved.q || '');
                            setSelectedRole(saved.role || '');
                            setSelectedType(saved.type || '');
                            setPage(1);
                        }}
                    />
                    {hasActiveFilters && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                setSelectedRole('');
                                setSelectedType('');
                                setPage(1);
                            }}
                        >
                            {t('admin.users.clearFilters')}
                        </Button>
                    )}
                </div>
                {pagination?.total != null && (
                    <div className="text-xs font-medium text-gray-500">
                        {t('admin.users.showingCount', { count: users.length, total: pagination.total })}
                    </div>
                )}
            </div>

            <div className={`transition-opacity duration-150 ${fetching ? 'opacity-60' : 'opacity-100'}`}>
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
                            <TableEmpty colSpan={4}>
                                {hasActiveFilters ? t('admin.users.noMatches') : t('admin.users.empty')}
                            </TableEmpty>
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

            {pagination && pagination.last_page > 1 && (
                <Pagination
                    page={pagination.current_page}
                    pages={pagination.last_page}
                    total={pagination.total}
                    onChange={(newPage) => setPage(newPage)}
                />
            )}
        </div>
    );
}
