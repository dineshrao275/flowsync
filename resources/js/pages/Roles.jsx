import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import usePageTitle from '../hooks/usePageTitle';
import { groupPermissionsByDomain, prettyDomain } from '../utils/permissions';
import { fieldClassCompact } from '../components/ui/fieldStyles';

const PRODUCT_BADGES = { tms: 'TMS', hrms: 'HRMS', shared: 'Shared' };

function productBadge(t, product) {
    if (product === 'tms') return t('admin.roles.productTms');
    if (product === 'hrms') return t('admin.roles.productHrms');
    return t('admin.roles.productShared');
}

function PermissionGrid({ permissions, isChecked, onToggle, disabled = false, dense = false }) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [collapsed, setCollapsed] = useState({});
    const needle = query.trim().toLowerCase();
    const groups = groupPermissionsByDomain(permissions)
        .map(({ domain, items }) => ({
            domain,
            all: items,
            items: needle
                ? items.filter((p) => `${p.name} ${p.slug} ${prettyDomain(domain)} ${PRODUCT_BADGES[p.product] ?? ''}`.toLowerCase().includes(needle))
                : items,
        }))
        .filter((g) => g.items.length > 0);
    const total = permissions.length;
    const selected = permissions.filter(isChecked).length;

    // Bulk change = one toggle per differing item; each toggle is a functional state update.
    const setMany = (items, checked) => items.forEach((p) => isChecked(p) !== checked && onToggle(p));

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={t('admin.roles.searchPlaceholder')}
                    className="min-w-48 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[var(--accent)]"
                />
                <span className="text-xs text-gray-500">{t('admin.roles.selectedCount', { selected, total })}</span>
            </div>
            {groups.length === 0 && <p className="text-sm text-gray-400">{t('admin.roles.noMatches', { query })}</p>}
            {groups.map(({ domain, items, all }) => {
                const on = all.filter(isChecked).length;
                const open = needle ? true : !collapsed[domain];

                return (
                    <div key={domain} className="rounded-lg border border-gray-100">
                        <div className="flex items-center justify-between gap-2 bg-gray-50 px-3 py-1.5">
                            <button
                                type="button"
                                onClick={() => setCollapsed((c) => ({ ...c, [domain]: !c[domain] }))}
                                className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500"
                            >
                                <span>{open ? '▾' : '▸'}</span>
                                {prettyDomain(domain)}
                                <span className="rounded-full bg-white px-1.5 py-0.5 text-xs font-medium normal-case text-gray-500">
                                    {on}/{all.length}
                                </span>
                            </button>
                            {!disabled && (
                                <span className="flex gap-3 text-xs">
                                    <button type="button" className="text-[var(--accent)] hover:underline" onClick={() => setMany(items, true)}>{t('admin.roles.allBtn')}</button>
                                    <button type="button" className="text-gray-500 hover:underline" onClick={() => setMany(items, false)}>{t('admin.common.none')}</button>
                                </span>
                            )}
                        </div>
                        {open && (
                            <div className="grid grid-cols-1 gap-2 p-2 sm:grid-cols-2 lg:grid-cols-3">
                                {items.map((permission) => (
                                    <label
                                        key={permission.id}
                                        className={`flex cursor-pointer rounded-lg text-sm transition hover:bg-gray-50 ${
                                            dense ? 'items-start gap-2 px-2 py-1.5' : 'items-center gap-2 border border-gray-200 px-3 py-2'
                                        } ${disabled ? 'cursor-default hover:bg-transparent' : ''}`}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={isChecked(permission)}
                                            disabled={disabled}
                                            onChange={() => onToggle(permission)}
                                            className="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-[var(--accent)] accent-[var(--accent)] transition-all duration-150 focus:ring-[var(--accent-ring)] disabled:cursor-not-allowed disabled:opacity-50"
                                        />
                                        <span className="text-gray-700">
                                            <span className="flex flex-wrap items-center gap-1.5">
                                                {permission.name}
                                                <span className="rounded bg-gray-100 px-1 py-px text-xs font-medium uppercase tracking-wide text-gray-500">
                                                    {productBadge(t, permission.product)}
                                                </span>
                                            </span>
                                            <span className="block text-xs text-gray-400">{permission.slug}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

export default function Roles() {
    const { t } = useTranslation();
    usePageTitle(t('admin.roles.pageTitle'));
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const [roles, setRoles] = useState([]);
    const [permissions, setPermissions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [editingId, setEditingId] = useState(null);
    const [viewingId, setViewingId] = useState(null);
    const [savingId, setSavingId] = useState(null);
    const [creating, setCreating] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', slug: '', permissions: [] });
    const [createErrors, setCreateErrors] = useState({});
    const [templates, setTemplates] = useState([]);

    async function load() {
        try {
            const { data } = await api.get('/roles');
            setRoles(data.roles);
            setPermissions(data.permissions);
        } catch (err) {
            if (err?.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }
            setError(t('admin.roles.loadFailed'));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        api.get('/roles/templates').then(({ data }) => setTemplates(data.templates || [])).catch(() => {});
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function applyTemplate(key) {
        if (!key) return;
        try {
            await api.post(`/roles/templates/${key}`);
            await load();
        } catch (err) {
            setError(fieldErrors(err).permissions || err.response?.data?.message || t('admin.roles.loadFailed'));
        }
    }

    async function createRole(e) {
        e.preventDefault();
        setSavingId('new');
        setCreateErrors({});
        try {
            const { data } = await api.post('/roles', createForm);
            setRoles((current) => [...current, data.role]);
            setCreating(false);
            setCreateForm({ name: '', slug: '', permissions: [] });
            toast.success(t('admin.roles.created', { name: data.role.name }));
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
            toast.success(t('admin.roles.updated', { name: role.name }));
            setEditingId(null);
        } catch (e) {
            setError(fieldErrors(e).form || t('admin.roles.saveFailed'));
        } finally {
            setSavingId(null);
        }
    }

    function duplicate(role) {
        setCreating(true);
        setCreateErrors({});
        setCreateForm({
            name: `${role.name}${t('admin.roles.copySuffix')}`,
            slug: `${role.slug}-copy`,
            permissions: (role.permissions || []).map((p) => p.id),
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function remove(role) {
        if (!(await confirm({ title: t('admin.roles.deleteTitle'), message: t('admin.roles.deleteMessage', { name: role.name }), variant: 'danger' }))) return;
        setSavingId(role.id);
        setError(null);
        try {
            await api.delete(`/roles/${role.id}`);
            setRoles((current) => current.filter((r) => r.id !== role.id));
            toast.success(t('admin.roles.deleted', { name: role.name }));
        } catch (e) {
            setError(fieldErrors(e).form || t('admin.roles.deleteFailed'));
        } finally {
            setSavingId(null);
        }
    }

    if (loading) {
        return (
            <ContentSkeleton size="lg" />
        );
    }

    const manageable = can('roles.manage');
    const viewing = roles.find((r) => r.id === viewingId) ?? null;
    const editing = manageable ? roles.find((r) => r.id === editingId) ?? null : null;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('admin.roles.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        {t('admin.roles.subtitle')}
                        {!manageable && t('admin.roles.viewOnly')}
                    </p>
                </div>
                {manageable && (
                    <div className="flex items-center gap-2">
                        {templates.length > 0 && (
                            <select aria-label={t('admin.roles.fromTemplate')} className={fieldClassCompact} value="" onChange={(e) => applyTemplate(e.target.value)}>
                                <option value="">{t('admin.roles.fromTemplate')}</option>
                                {templates.map((tpl) => <option key={tpl.key} value={tpl.key}>{tpl.name} ({tpl.permission_count})</option>)}
                            </select>
                        )}
                        <Button onClick={() => setCreating((open) => !open)}>{t('admin.roles.newRole')}</Button>
                    </div>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {creating && (
                <Card title={t('admin.roles.createTitle')} subtitle={t('admin.roles.createSubtitle')}>
                    <form onSubmit={createRole} className="space-y-4">
                        {createErrors.form && <Alert>{createErrors.form}</Alert>}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Input
                                label={t('admin.roles.roleName')}
                                name="name"
                                placeholder={t('admin.roles.roleNamePlaceholder')}
                                value={createForm.name}
                                onChange={(e) => setCreateForm((f) => ({ ...f, name: e.target.value }))}
                                error={createErrors.name}
                                required
                            />
                            <Input
                                label={t('admin.roles.slug')}
                                name="slug"
                                placeholder={t('admin.roles.slugPlaceholder')}
                                value={createForm.slug}
                                onChange={(e) => setCreateForm((f) => ({ ...f, slug: e.target.value }))}
                                error={createErrors.slug}
                                required
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.roles.permissions')}</label>
                            <PermissionGrid
                                permissions={permissions}
                                isChecked={(permission) => createForm.permissions.includes(permission.id)}
                                onToggle={(permission) =>
                                    setCreateForm((f) => ({
                                        ...f,
                                        permissions: f.permissions.includes(permission.id)
                                            ? f.permissions.filter((id) => id !== permission.id)
                                            : [...f.permissions, permission.id],
                                    }))
                                }
                            />
                            {createErrors.permissions && (
                                <p className="mt-1.5 text-sm text-red-600">{createErrors.permissions}</p>
                            )}
                        </div>
                        <div className="flex gap-2 pt-1">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>
                                {t('admin.common.cancel')}
                            </Button>
                            <Button type="submit" loading={savingId === 'new'}>
                                {t('admin.roles.createRole')}
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            <Table>
                <thead>
                    <tr>
                        <Th>{t('admin.common.role')}</Th>
                        <Th>{t('admin.roles.slug')}</Th>
                        <Th>{t('admin.roles.permissions')}</Th>
                        <Th align="right">{t('admin.roles.thMembers')}</Th>
                        <Th align="right">{t('admin.common.actions')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {roles.length === 0 ? (
                        <TableEmpty colSpan={5}>{t('admin.roles.empty')}</TableEmpty>
                    ) : (
                        roles.map((role) => {
                            const count = role.permissions?.length ?? 0;
                            const all = permissions.length > 0 && count >= permissions.length;

                            return (
                                <tr key={role.id} className="transition-colors duration-150 hover:bg-gray-50">
                                    <Td><span className="font-medium capitalize">{role.name}</span></Td>
                                    <Td>
                                        <Badge>{role.slug}</Badge>
                                        {role.is_system && <span className="ml-2 text-xs text-gray-500">{t('admin.roles.builtIn')}</span>}
                                    </Td>
                                    <Td className="text-gray-600">
                                        {count === 0
                                            ? t('admin.roles.noPermissions')
                                            : all
                                                ? t('admin.roles.allPermissions')
                                                : t('admin.roles.permissionsSummary', { count, total: permissions.length })}
                                    </Td>
                                    <Td align="right" className="tabular-nums">{role.users_count}</Td>
                                    <Td align="right">
                                        <div className="flex justify-end gap-2">
                                            <Button variant="secondary" size="sm" onClick={() => setViewingId(role.id)}>
                                                {t('admin.common.view')}
                                            </Button>
                                            {manageable && !role.is_system && (
                                                <Button variant="secondary" size="sm" onClick={() => setEditingId(role.id)}>
                                                    {t('admin.roles.editPermissions')}
                                                </Button>
                                            )}
                                            {manageable && (
                                                <Button variant="secondary" size="sm" onClick={() => duplicate(role)}>
                                                    {t('admin.roles.duplicate')}
                                                </Button>
                                            )}
                                            {manageable && !role.is_system && (
                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    loading={savingId === role.id}
                                                    disabled={role.users_count > 0}
                                                    title={role.users_count > 0 ? t('admin.roles.cannotDelete') : undefined}
                                                    onClick={() => remove(role)}
                                                >
                                                    {t('admin.common.delete')}
                                                </Button>
                                            )}
                                        </div>
                                    </Td>
                                </tr>
                            );
                        })
                    )}
                </tbody>
            </Table>

            <Modal
                open={Boolean(viewing)}
                onClose={() => setViewingId(null)}
                title={viewing?.name}
                subtitle={viewing ? t('admin.roles.permissionsSummary', { count: viewing.permissions?.length ?? 0, total: permissions.length }) : undefined}
                size="lg"
            >
                {viewing && (
                    <div className="px-6 py-5">
                        {(viewing.permissions?.length ?? 0) === 0 ? (
                            <p className="text-sm text-gray-500">{t('admin.roles.noPermissions')}</p>
                        ) : (
                            <PermissionGrid
                                permissions={permissions.filter((permission) => viewing.permissions.some((p) => p.id === permission.id))}
                                isChecked={() => true}
                                onToggle={() => {}}
                                disabled
                                dense
                            />
                        )}
                    </div>
                )}
            </Modal>

            <Modal
                open={Boolean(editing)}
                onClose={() => { setEditingId(null); load(); }}
                title={editing ? t('admin.roles.editPermissions') : undefined}
                subtitle={editing?.name}
                size="lg"
            >
                {editing && (
                    <div className="space-y-4 px-6 py-5">
                        <Input
                            label={t('admin.roles.roleName')}
                            value={editing.name}
                            onChange={(e) => setRoles((current) => current.map((r) => (r.id === editing.id ? { ...r, name: e.target.value } : r)))}
                        />
                        <PermissionGrid
                            permissions={permissions}
                            dense
                            isChecked={(permission) => editing.permissions.some((p) => p.id === permission.id)}
                            onToggle={(permission) => togglePermission(editing.id, permission.id)}
                        />
                        <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                            <Button variant="secondary" onClick={() => { setEditingId(null); load(); }}>{t('admin.common.cancel')}</Button>
                            <Button loading={savingId === editing.id} onClick={() => save(editing)}>{t('admin.roles.saveRole')}</Button>
                        </div>
                    </div>
                )}
            </Modal>
        </div>
    );
}