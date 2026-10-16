import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Menu from '../components/ui/Menu';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import usePageTitle from '../hooks/usePageTitle';
import { groupPermissionsByDomain, prettyDomain } from '../utils/permissions';

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

const CHIP_LIMIT = 6;

/**
 * One role as a card instead of a table row: the old row crammed every granted
 * permission into a single comma-separated cell (80+ names for the admin role).
 * Here the grants are summarised (count + bar), grouped by domain into chips,
 * and the full per-domain breakdown is one click away. Editing opens the
 * grouped, searchable PermissionGrid inside the same card at full width.
 */
function RoleCard({ role, permissions, manageable, editing, saving, onRename, onToggle, onEdit, onCancel, onSave, onDuplicate, onDelete }) {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);
    const selectedIds = new Set((role.permissions ?? []).map((p) => p.id));
    const granted = permissions.filter((permission) => selectedIds.has(permission.id));
    const groups = groupPermissionsByDomain(granted);
    const total = permissions.length;
    const all = total > 0 && granted.length >= total;
    const visibleChips = expanded ? groups : groups.slice(0, CHIP_LIMIT);
    const hidden = groups.length - CHIP_LIMIT;
    const deleteBlocked = role.users_count > 0;

    const menuItems = [
        { key: 'duplicate', label: t('admin.roles.duplicate'), onSelect: onDuplicate },
        ...(!role.is_system
            ? [{ separator: true }, { key: 'delete', label: deleteBlocked ? `${t('admin.common.delete')} — ${t('admin.roles.cannotDelete')}` : t('admin.common.delete'), danger: true, disabled: deleteBlocked, onSelect: onDelete }]
            : []),
    ];

    return (
        <Card className={editing ? 'xl:col-span-2' : ''}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    {editing ? (
                        <input
                            value={role.name}
                            onChange={(e) => onRename(e.target.value)}
                            aria-label={t('admin.roles.roleName')}
                            className="w-full max-w-xs rounded-lg border border-line-input px-2.5 py-1.5 text-base font-semibold text-fg"
                        />
                    ) : (
                        <h3 className="truncate text-base font-semibold capitalize text-fg">{role.name}</h3>
                    )}
                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                        <Badge tone="neutral">{role.slug}</Badge>
                        {role.is_system && <Badge tone="info">{t('admin.roles.builtIn')}</Badge>}
                        <span className="text-caption text-fg-muted">{t(role.users_count === 1 ? 'admin.roles.membersCountOne' : 'admin.roles.membersCount', { count: role.users_count })}</span>
                    </div>
                </div>
                {manageable && !editing && (
                    <div className="flex shrink-0 items-center gap-2">
                        {!role.is_system && (
                            <Button variant="secondary" size="sm" onClick={onEdit}>{t('admin.roles.editPermissions')}</Button>
                        )}
                        <Menu
                            label={t('admin.roles.actionsLabel', { name: role.name })}
                            items={menuItems}
                            trigger={(props) => (
                                <Button {...props} variant="ghost" size="sm" className="px-2" loading={saving}>
                                    <span aria-hidden="true">⋯</span>
                                </Button>
                            )}
                        />
                    </div>
                )}
            </div>

            {!editing && (
                <div className="mt-4">
                    <div className="flex items-center justify-between text-caption">
                        <span className="font-medium text-fg">
                            {all ? t('admin.roles.allPermissions') : t('admin.roles.permissionsSummary', { count: granted.length, total })}
                        </span>
                        {granted.length > 0 && !all && <span className="text-fg-subtle">{Math.round((granted.length / Math.max(total, 1)) * 100)}%</span>}
                    </div>
                    <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-sunken" aria-hidden="true">
                        <div className="h-full rounded-full bg-[var(--accent)]" style={{ width: `${(granted.length / Math.max(total, 1)) * 100}%` }} />
                    </div>

                    {granted.length === 0 ? (
                        <p className="mt-3 text-sm text-fg-subtle">{t('admin.roles.noPermissions')}</p>
                    ) : expanded ? (
                        <dl className="mt-3 space-y-2.5">
                            {groups.map(({ domain, items }) => (
                                <div key={domain}>
                                    <dt className="text-caption font-semibold uppercase tracking-wide text-fg-muted">
                                        {prettyDomain(domain)} <span className="font-normal normal-case text-fg-subtle">({items.length})</span>
                                    </dt>
                                    <dd className="mt-1 flex flex-wrap gap-1.5">
                                        {items.map((permission) => (
                                            <span key={permission.id} title={permission.slug} className="rounded-md bg-surface-sunken px-2 py-0.5 text-caption text-fg ring-1 ring-inset ring-line">
                                                {permission.name}
                                            </span>
                                        ))}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    ) : (
                        <ul className="mt-3 flex flex-wrap gap-1.5" aria-label={t('admin.roles.permissions')}>
                            {visibleChips.map(({ domain, items }) => (
                                <li key={domain} className="rounded-full bg-[var(--accent-soft)] px-2.5 py-0.5 text-caption font-medium text-[var(--accent-soft-text)]">
                                    {prettyDomain(domain)} <span className="opacity-70">{items.length}</span>
                                </li>
                            ))}
                            {hidden > 0 && (
                                <li className="rounded-full bg-surface-sunken px-2.5 py-0.5 text-caption text-fg-muted">{t('admin.roles.moreChips', { count: hidden })}</li>
                            )}
                        </ul>
                    )}

                    {granted.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setExpanded((open) => !open)}
                            aria-expanded={expanded}
                            className="mt-3 text-caption font-medium text-[var(--accent-soft-text)] hover:underline"
                        >
                            {expanded ? t('admin.roles.hideAll') : t('admin.roles.showAll')}
                        </button>
                    )}
                </div>
            )}

            {editing && (
                <div className="mt-4">
                    <PermissionGrid
                        permissions={permissions}
                        disabled={!manageable}
                        dense
                        isChecked={(permission) => selectedIds.has(permission.id)}
                        onToggle={onToggle}
                    />
                    <div className="mt-4 flex justify-end gap-2 border-t border-line pt-4">
                        <Button variant="secondary" size="sm" onClick={onCancel}>{t('admin.common.cancel')}</Button>
                        <Button size="sm" loading={saving} onClick={onSave}>{t('admin.roles.saveRole')}</Button>
                    </div>
                </div>
            )}
        </Card>
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
    const [savingId, setSavingId] = useState(null);
    const [creating, setCreating] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', slug: '', permissions: [] });
    const [createErrors, setCreateErrors] = useState({});

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
                    <h2 className="text-2xl font-bold text-gray-900">{t('admin.roles.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        {t('admin.roles.subtitle')}
                        {!manageable && t('admin.roles.viewOnly')}
                    </p>
                </div>
                {manageable && (
                    <Button onClick={() => setCreating((open) => !open)}>{t('admin.roles.newRole')}</Button>
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

            {roles.length === 0 ? (
                <Card>
                    <p className="py-6 text-center text-sm text-fg-subtle">{t('admin.roles.empty')}</p>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    {roles.map((role) => (
                        <RoleCard
                            key={role.id}
                            role={role}
                            permissions={permissions}
                            manageable={manageable}
                            editing={manageable && editingId === role.id}
                            saving={savingId === role.id}
                            onRename={(name) => setRoles((current) => current.map((r) => (r.id === role.id ? { ...r, name } : r)))}
                            onToggle={(permission) => togglePermission(role.id, permission.id)}
                            onEdit={() => setEditingId(role.id)}
                            onCancel={() => { setEditingId(null); load(); }}
                            onSave={() => save(role)}
                            onDuplicate={() => duplicate(role)}
                            onDelete={() => remove(role)}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}