import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from '../services/api';
import { useAuth } from './AuthContext';
import { useToast } from './ToastContext';
import { describeNotification } from '../utils/notifications';

const NotificationContext = createContext(null);

export function NotificationProvider({ children }) {
    const { user } = useAuth();
    const toast = useToast();
    const [unreadCount, setUnreadCount] = useState(0);

    // A non-impersonating super admin has no tenant database: personal
    // notifications live in the tenant DB only, so there is nothing to poll.
    const platformOnly = Boolean(user?.is_super_admin && !user?.impersonating);

    const refresh = useCallback(async () => {
        if (!user || platformOnly) {
            setUnreadCount(0);
            return;
        }
        try {
            const { data } = await api.get('/notifications/unread');
            setUnreadCount(data.count);
        } catch {
            // keep current count on transient failures
        }
    }, [user, platformOnly]);

    useEffect(() => {
        refresh();
        if (platformOnly) return undefined;
        const timer = window.setInterval(refresh, 30000);
        return () => window.clearInterval(timer);
    }, [refresh, platformOnly]);

    useEffect(() => {
        if (!user || platformOnly || !window.Echo) return undefined;
        const channel = window.Echo.private(`user.${user.id}`);
        const handler = (event) => {
            setUnreadCount((count) => count + 1);
            toast.info(describeNotification(event.type, event.data ?? {}, event.actor?.name ?? 'Someone'));
        };
        channel.listen('.notification.sent', handler);
        return () => channel.stopListening('.notification.sent');
    }, [user, platformOnly, toast]);

    const markAllRead = useCallback(async () => {
        await api.post('/notifications/mark-all-read');
        setUnreadCount(0);
    }, []);

    const markRead = useCallback(async (id) => {
        await api.post(`/notifications/${id}/read`);
        setUnreadCount((count) => Math.max(0, count - 1));
    }, []);

    const value = useMemo(
        () => ({ unreadCount, refresh, markAllRead, markRead }),
        [unreadCount, refresh, markAllRead, markRead],
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