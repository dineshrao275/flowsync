import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Spinner from './ui/Spinner';
import { Bell, Users } from '@phosphor-icons/react';
import { useNotifications } from '../context/NotificationContext';
import { useClickOutside } from '../hooks/useClickOutside';
import { describeNotification, notificationHref } from '../utils/notifications';
import Avatar from './ui/Avatar';
import Timestamp from './ui/Timestamp';

export default function NotificationBell() {
    const { t } = useTranslation();
    const { unreadCount, markAllRead, markRead } = useNotifications();
    const [open, setOpen] = useState(false);
    const [notifications, setNotifications] = useState(null);
    const containerRef = useRef(null);
    const navigate = useNavigate();

    useClickOutside(containerRef, () => setOpen(false));

    useEffect(() => {
        if (!open) return;
        setNotifications(null);
        api.get('/notifications', { params: { per_page: 8 } })
            .then(({ data }) => setNotifications(data.notifications))
            .catch(() => setNotifications([]));
    }, [open]);

    function handleOpen(notification) {
        markRead(notification.id);
        setOpen(false);
        navigate(notificationHref(notification.data ?? {}, notification.type));
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
                {unreadCount > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                        {unreadCount > 99 ? '99+' : unreadCount}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute right-0 z-30 mt-2 w-80 origin-top-right animate-scale-in overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg">
                    <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <p className="text-sm font-semibold text-gray-900">{t('notifications.pageTitle')}</p>
                        {unreadCount > 0 && (
                            <button
                                onClick={markAllRead}
                                className="text-xs font-medium text-[var(--accent-soft-text)] transition hover:opacity-80"
                            >
                                {t('notifications.markAllReadShort')}
                            </button>
                        )}
                    </div>

                    <div className="max-h-96 overflow-y-auto">
                        {notifications === null ? (
                            <div className="flex justify-center py-10">
                                <Spinner />
                            </div>
                        ) : notifications.length === 0 ? (
                            <p className="px-4 py-10 text-center text-sm text-gray-400">{t('notifications.caughtUp')}</p>
                        ) : (
                            <ul className="divide-y divide-gray-50">
                                {notifications.map((notification) => (
                                    <li key={notification.id}>
                                        <button
                                            onClick={() => handleOpen(notification)}
                                            className="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-gray-50"
                                        >
                                            <span className="mt-1">
                                                {notification.type?.startsWith('hrms.') ? (
                                                    <span className="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--accent-soft)] text-[var(--accent-soft-text)]">
                                                        <Users size={16} aria-hidden="true" />
                                                    </span>
                                                ) : (
                                                    <Avatar name={notification.actor?.name} size="md" />
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

                    <button
                        onClick={() => {
                            setOpen(false);
                            navigate('/notifications');
                        }}
                        className="w-full border-t border-gray-100 px-4 py-2.5 text-center text-sm font-medium text-[var(--accent-soft-text)] transition hover:bg-[var(--accent-soft)]"
                    >
                        {t('notifications.viewAll')}
                    </button>
                </div>
            )}
        </div>
    );
}