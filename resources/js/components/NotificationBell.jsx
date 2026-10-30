import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import ContentSkeleton from './ui/ContentSkeleton';
import { Bell, Envelope, Lifebuoy, Lightning, Users } from '@phosphor-icons/react';
import { useAuth } from '../context/AuthContext';
import { useNotifications } from '../context/NotificationContext';
import { useClickOutside } from '../hooks/useClickOutside';
import { describeNotification, notificationHref } from '../utils/notifications';
import Avatar from './ui/Avatar';
import Timestamp from './ui/Timestamp';
import UserTooltip from './ui/UserTooltip';

export default function NotificationBell() {
    const { t } = useTranslation();
    const { user } = useAuth();
    const {
        unreadCount,
        markAllRead,
        markRead,
        platformAlerts,
        platformUnreadCount,
        markPlatformAlertRead,
        markAllPlatformAlertsRead,
    } = useNotifications();
    const [open, setOpen] = useState(false);
    const [notifications, setNotifications] = useState(null);
    const containerRef = useRef(null);
    const navigate = useNavigate();

    const platformOnly = Boolean(user?.is_super_admin && !user?.impersonating);
    const effectiveCount = platformOnly ? platformUnreadCount : unreadCount;

    useClickOutside(containerRef, () => setOpen(false));

    useEffect(() => {
        if (!open || platformOnly) return;
        setNotifications(null);
        api.get('/notifications', { params: { per_page: 8 } })
            .then(({ data }) => setNotifications(data.notifications))
            .catch(() => setNotifications([]));
    }, [open, platformOnly]);

    function handleOpenTenant(notification) {
        markRead(notification.id);
        setOpen(false);
        navigate(notificationHref(notification.data ?? {}, notification.type));
    }

    function handleOpenPlatform(alert) {
        markPlatformAlertRead(alert.id);
        setOpen(false);
        navigate(alert.to);
    }

    return (
        <div className="relative" ref={containerRef}>
            <button
                onClick={() => setOpen((value) => !value)}
                className="relative rounded-lg p-2 transition-all duration-150 hover:bg-black/5 active:scale-90"
                aria-label={t('notifications.pageTitle')}
                style={{ color: 'var(--header-text)' }}
            >
                <Bell size={20} aria-hidden="true" />
                {effectiveCount > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-xs font-bold text-white">
                        {effectiveCount > 99 ? '99+' : effectiveCount}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute right-0 z-30 mt-2 w-80 origin-top-right animate-scale-in overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg">
                    <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <p className="text-sm font-semibold text-gray-900">{t('notifications.pageTitle')}</p>
                        {effectiveCount > 0 && (
                            <button
                                onClick={platformOnly ? markAllPlatformAlertsRead : markAllRead}
                                className="text-xs font-medium text-[var(--accent-soft-text)] transition hover:opacity-80"
                            >
                                {t('notifications.markAllReadShort')}
                            </button>
                        )}
                    </div>

                    <div className="max-h-96 overflow-y-auto">
                        {platformOnly ? (
                            platformAlerts.length === 0 ? (
                                <p className="px-4 py-10 text-center text-sm text-gray-400">{t('notifications.caughtUp')}</p>
                            ) : (
                                <ul className="divide-y divide-gray-50">
                                    {platformAlerts.map((alert) => (
                                        <li key={alert.id}>
                                            <button
                                                onClick={() => handleOpenPlatform(alert)}
                                                className="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-gray-50"
                                            >
                                                <span className="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--accent-soft)] text-[var(--accent-soft-text)]">
                                                    {alert.type === 'leads' && <Envelope size={16} aria-hidden="true" />}
                                                    {alert.type === 'support' && <Lifebuoy size={16} aria-hidden="true" />}
                                                    {alert.type === 'queue' && <Lightning size={16} aria-hidden="true" />}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm font-medium text-gray-800">{alert.title}</span>
                                                    {alert.message && (
                                                        <span className="block truncate text-xs text-gray-500">{alert.message}</span>
                                                    )}
                                                    <span className="block text-xs text-gray-400">
                                                        <Timestamp date={alert.time} placement="bottom" />
                                                    </span>
                                                </span>
                                                {!alert.read && <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-[var(--accent)]" />}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )
                        ) : notifications === null ? (
                            <ContentSkeleton size="md" />
                        ) : notifications.length === 0 ? (
                            <p className="px-4 py-10 text-center text-sm text-gray-400">{t('notifications.caughtUp')}</p>
                        ) : (
                            <ul className="divide-y divide-gray-50">
                                {notifications.map((notification) => (
                                    <li key={notification.id}>
                                        <button
                                            onClick={() => handleOpenTenant(notification)}
                                            className="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-gray-50"
                                        >
                                            <span className="mt-1">
                                                {notification.type?.startsWith('hrms.') ? (
                                                    <span className="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--accent-soft)] text-[var(--accent-soft-text)]">
                                                        <Users size={16} aria-hidden="true" />
                                                    </span>
                                                ) : (
                                                    <UserTooltip user={notification.actor} name={notification.actor?.name}>
                                                        <Avatar name={notification.actor?.name} size="md" />
                                                    </UserTooltip>
                                                )}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-sm text-gray-800">
                                                    {describeNotification(
                                                        t,
                                                        notification.type,
                                                        notification.data ?? {},
                                                        notification.actor?.name ?? null,
                                                    )}
                                                </span>
                                                <span className="block text-xs text-gray-400">
                                                    <Timestamp date={notification.created_at} placement="bottom" />
                                                </span>
                                            </span>
                                            {!notification.read_at && <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-[var(--accent)]" />}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {!platformOnly && (
                        <button
                            onClick={() => {
                                setOpen(false);
                                navigate('/notifications');
                            }}
                            className="w-full border-t border-gray-100 px-4 py-2.5 text-center text-sm font-medium text-[var(--accent-soft-text)] transition hover:bg-[var(--accent-soft)]"
                        >
                            {t('notifications.viewAll')}
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}