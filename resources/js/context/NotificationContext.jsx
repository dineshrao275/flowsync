import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import { useAuth } from './AuthContext';
import { useToast } from './ToastContext';
import { describeNotification } from '../utils/notifications';
import { onRealtimeReconnect } from '../utils/realtimeAuth';

const NotificationContext = createContext(null);

export function NotificationProvider({ children }) {
    const { t } = useTranslation();
    const { user } = useAuth();
    const toast = useToast();
    const [unreadCount, setUnreadCount] = useState(0);
    const [inboxUnread, setInboxUnread] = useState(0);

    // A non-impersonating super admin has no tenant database: personal
    // notifications live in the tenant DB only, so there is nothing to poll.
    const platformOnly = Boolean(user?.is_super_admin && !user?.impersonating);

    const refresh = useCallback(async () => {
        if (!user || platformOnly) {
            setUnreadCount(0);
            setInboxUnread(0);
            return;
        }
        try {
            const { data } = await api.get('/notifications/unread');
            setUnreadCount(data.count);
        } catch {
            // keep current count on transient failures
        }
        // The HR queue rides the same 30s cadence: one timer, two counts,
        // so the sidebar badge never drifts a full cycle behind the bell.
        try {
            const { data } = await api.get('/hrms/inbox', { params: { per_page: 1 } });
            setInboxUnread(data.unread_count ?? 0);
        } catch {
            // keep current count on transient failures
        }
    }, [user, platformOnly]);

    useEffect(() => {
        refresh();
        if (platformOnly) return undefined;
        // Push-first: When Reverb/Echo is active, fallback polling runs on a slower
        // 60s cadence to minimize server load; without websockets, it falls back to 30s.
        const intervalMs = window.Echo ? 60000 : 30000;
        const timer = window.setInterval(refresh, intervalMs);
        return () => window.clearInterval(timer);
    }, [refresh, platformOnly]);

    useEffect(() => {
        if (!user || platformOnly || !window.Echo) return undefined;
        const channel = window.Echo.private(`user.${user.id}`);
        const handler = (event) => {
            setUnreadCount((count) => count + 1);
            toast.info(describeNotification(t, event.type, event.data ?? {}, event.actor?.name ?? null));
            // The bell is bumped optimistically above; re-read so the HR inbox
            // badge (and any count drift) settles immediately instead of at the next poll.
            refresh();
        };
        channel.listen('.notification.sent', handler);
        // Pushes sent while the socket was down are gone: re-read on reconnect.
        const stopReconnect = onRealtimeReconnect(refresh);

        return () => {
            channel.stopListening('.notification.sent', handler);
            stopReconnect();
            // Leave on sign-out / user switch / impersonation so a previous
            // account's channel never keeps delivering into this session.
            window.Echo.leave(`user.${user.id}`);
        };
    }, [user, platformOnly, toast, t, refresh]);

    const markAllRead = useCallback(async () => {
        await api.post('/notifications/mark-all-read');
        setUnreadCount(0);
    }, []);

    const markRead = useCallback(async (id) => {
        await api.post(`/notifications/${id}/read`);
        setUnreadCount((count) => Math.max(0, count - 1));
    }, []);

    const value = useMemo(
        () => ({ unreadCount, inboxUnread, refresh, markAllRead, markRead }),
        [unreadCount, inboxUnread, refresh, markAllRead, markRead],
    );

    return <NotificationContext.Provider value={value}>{children}</NotificationContext.Provider>;
}

export function useNotifications() {
    const context = useContext(NotificationContext);
    if (!context) {
        throw new Error('useNotifications must be used within a NotificationProvider');
    }
    return context;
}