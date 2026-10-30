import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import { useConfirm } from '../ui/ConfirmDialog';
import FloatingPanel from '../ui/FloatingPanel';
import Badge from '../ui/Badge';

export function TenantActions({ tenant, onChanged, onImpersonate }) {
    const { t } = useTranslation();
    const ref = useRef(null);
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState('menu');
    const [users, setUsers] = useState([]);
    const [search, setSearch] = useState('');
    const [loadingUsers, setLoadingUsers] = useState(false);
    const [busy, setBusy] = useState(false);

    const panelRef = useRef(null);

    const sortedUsers = useMemo(() => {
        const getWeight = (u) => {
            if (u.is_default) return 0;
            const slugs = (u.roles || []).map((r) => (typeof r === 'string' ? r : (r.slug || r.name || '')).toLowerCase());
            if (slugs.includes('admin')) return 1;
            if (slugs.includes('manager')) return 2;
            if (slugs.includes('editor')) return 3;
            if (slugs.includes('member')) return 4;
            if (slugs.includes('viewer')) return 5;
            if (slugs.includes('employee')) return 6;
            return 10;
        };
        return [...users].sort((a, b) => {
            const wA = getWeight(a);
            const wB = getWeight(b);
            if (wA !== wB) return wA - wB;
            return (a.name || '').localeCompare(b.name || '');
        });
    }, [users]);

    const filteredUsers = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return sortedUsers;
        return sortedUsers.filter((u) => {
            const nameMatch = (u.name || '').toLowerCase().includes(q);
            const emailMatch = (u.email || '').toLowerCase().includes(q);
            const roleMatch = (u.roles || []).some((role) => {
                const text = typeof role === 'string' ? role : `${role.name || ''} ${role.slug || ''}`;
                return text.toLowerCase().includes(q);
            });
            return nameMatch || emailMatch || roleMatch;
        });
    }, [sortedUsers, search]);

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
            if (!current) {
                setMode('menu');
                setSearch('');
            }
            return !current;
        });
    }

    async function loadUsers() {
        setMode('users');
        setSearch('');
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
                <FloatingPanel ref={panelRef} anchorRef={ref} className={`${mode === 'users' ? 'w-80' : 'w-52'} origin-top-right animate-scale-in overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl`}>
                    {mode === 'users' ? (
                        <>
                            <div className="flex items-center justify-between border-b border-gray-100 px-3 py-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setMode('menu');
                                        setSearch('');
                                    }}
                                    className="flex items-center gap-1.5 text-xs font-medium text-gray-500 transition hover:text-gray-800"
                                >
                                    <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M19 12H5M12 19l-7-7 7-7" />
                                    </svg>
                                    {t('admin.tenants.back', 'Back')}
                                </button>
                                <span className="text-xs font-medium text-gray-500">
                                    {t('admin.tenants.viewAsUser', 'View as user')}
                                </span>
                            </div>
                            <div className="border-b border-gray-100 bg-gray-50/50 p-2">
                                <div className="relative">
                                    <svg className="pointer-events-none absolute left-2.5 top-2.5 h-3.5 w-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                    <input
                                        type="text"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder={t('admin.users.searchPlaceholder', 'Search by name, email, or role...')}
                                        className="w-full rounded-lg border border-gray-200 bg-white py-1.5 pl-8 pr-7 text-xs text-gray-800 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                                        autoFocus
                                    />
                                    {search && (
                                        <button
                                            type="button"
                                            onClick={() => setSearch('')}
                                            className="absolute right-2 top-2 text-gray-400 hover:text-gray-600"
                                            aria-label={t('common.clear', 'Clear')}
                                        >
                                            <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                <path d="M18 6 6 18M6 6l12 12" />
                                            </svg>
                                        </button>
                                    )}
                                </div>
                            </div>
                            <div className="max-h-72 overflow-y-auto divide-y divide-gray-50">
                                {loadingUsers ? (
                                    <p className="px-4 py-3 text-sm text-gray-400">{t('admin.common.loading')}</p>
                                ) : filteredUsers.length === 0 ? (
                                    <p className="px-4 py-4 text-center text-xs text-gray-400">
                                        {search
                                            ? t('admin.users.noMatchesFor', { query: search, defaultValue: `No users matching "${search}"` })
                                            : t('admin.common.noResults', 'No users found')}
                                    </p>
                                ) : (
                                    filteredUsers.map((user) => (
                                        <button
                                            key={user.id}
                                            type="button"
                                            onClick={() => onImpersonate(user)}
                                            className="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm transition hover:bg-gray-50 group"
                                        >
                                            <span className="min-w-0 flex-1">
                                                <span className="flex items-center gap-1.5">
                                                    <span className="truncate font-medium text-gray-800 group-hover:text-blue-600 transition-colors">
                                                        {user.name}
                                                    </span>
                                                    {user.is_default && (
                                                        <span className="inline-flex items-center rounded px-1.5 py-0.2 text-[10px] font-medium bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20">
                                                            {t('admin.users.defaultBadge', 'Default')}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="block truncate text-xs text-gray-500">{user.email}</span>
                                            </span>
                                            <span className="flex flex-shrink-0 flex-wrap items-center justify-end gap-1">
                                                {user.roles && user.roles.length > 0 ? (
                                                    user.roles.map((role) => {
                                                        const roleSlug = typeof role === 'string' ? role : (role.slug || role.name);
                                                        const roleName = typeof role === 'string' ? role : (role.name || role.slug);
                                                        return (
                                                            <Badge key={roleSlug} className="text-[10px] px-2 py-0.5 capitalize">
                                                                {roleName}
                                                            </Badge>
                                                        );
                                                    })
                                                ) : (
                                                    <span className="text-[10px] text-gray-400 italic">
                                                        {t('admin.users.noRole', 'No role')}
                                                    </span>
                                                )}
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

export function ActionLink({ to, onClick, children }) {
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

export function ActionButton({ children, onClick, disabled, danger = false }) {
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
