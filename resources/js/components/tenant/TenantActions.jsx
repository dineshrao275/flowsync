import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import { useConfirm } from '../ui/ConfirmDialog';
import FloatingPanel from '../ui/FloatingPanel';

export function TenantActions({ tenant, onChanged, onImpersonate }) {
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
