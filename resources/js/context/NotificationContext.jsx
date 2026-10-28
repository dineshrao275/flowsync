import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import { useAuth } from './AuthContext';
import { useToast } from './ToastContext';
import { describeNotification } from '../utils/notifications';
import { onRealtimeReconnect } from '../utils/realtimeAuth';

const NotificationContext = createContext(null);

const STORAGE_KEY_ALERTS = 'flowsync_platform_alerts';
const STORAGE_KEY_VIEWED = 'flowsync_platform_viewed_sections';

export function NotificationProvider({ children }) {
    const { t } = useTranslation();
    const { user } = useAuth();
    const location = useLocation();
    const toast = useToast();
    const [unreadCount, setUnreadCount] = useState(0);
    const [inboxUnread, setInboxUnread] = useState(0);
    const [platformCounts, setPlatformCounts] = useState({ leads: 0, support: 0, queue: 0 });

    const [viewedSections, setViewedSections] = useState(() => {
        try {
            return JSON.parse(sessionStorage.getItem(STORAGE_KEY_VIEWED) || '{}');
        } catch {
            return {};
        }
    });

    const [platformAlerts, setPlatformAlerts] = useState(() => {
        try {
            return JSON.parse(sessionStorage.getItem(STORAGE_KEY_ALERTS) || '[]');
        } catch {
            return [];
        }
    });

    // A non-impersonating super admin has no tenant database: personal
    // notifications live in the tenant DB only, but they monitor platform-wide
    // events (marketing leads, support tickets, background queue failures).
    const platformOnly = Boolean(user?.is_super_admin && !user?.impersonating);

    const markPlatformSectionViewed = useCallback((sectionKey) => {
        setViewedSections((prev) => {
            const next = { ...prev, [sectionKey]: true };
            try {
                sessionStorage.setItem(STORAGE_KEY_VIEWED, JSON.stringify(next));
            } catch {
                // ignore
            }
            return next;
        });

        setPlatformAlerts((prev) => {
            const next = prev.map((alert) =>
                alert.type === sectionKey ? { ...alert, read: true } : alert,
            );
            try {
                sessionStorage.setItem(STORAGE_KEY_ALERTS, JSON.stringify(next));
            } catch {
                // ignore
            }
            return next;
        });
    }, []);

    const markPlatformAlertRead = useCallback((alertId) => {
        let alertType = null;
        setPlatformAlerts((prev) => {
            const target = prev.find((a) => a.id === alertId);
            if (target) alertType = target.type;
            const next = prev.map((alert) =>
                alert.id === alertId ? { ...alert, read: true } : alert,
            );
            try {
                sessionStorage.setItem(STORAGE_KEY_ALERTS, JSON.stringify(next));
            } catch {
                // ignore
            }
            return next;
        });

        if (alertType) {
            setViewedSections((prev) => {
                const next = { ...prev, [alertType]: true };
                try {
                    sessionStorage.setItem(STORAGE_KEY_VIEWED, JSON.stringify(next));
                } catch {
                    // ignore
                }
                return next;
            });
        }
    }, []);

    const markAllPlatformAlertsRead = useCallback(() => {
        setPlatformAlerts((prev) => {
            const next = prev.map((alert) => ({ ...alert, read: true }));
            try {
                sessionStorage.setItem(STORAGE_KEY_ALERTS, JSON.stringify(next));
            } catch {
                // ignore
            }
            return next;
        });
        setViewedSections(() => {
            const next = { leads: true, support: true, queue: true };
            try {
                sessionStorage.setItem(STORAGE_KEY_VIEWED, JSON.stringify(next));
            } catch {
                // ignore
            }
            return next;
        });
    }, []);

    // Dismiss badge as soon as the user navigates to the corresponding page to view the info
    useEffect(() => {
        if (!platformOnly) return;
        if (location.pathname === '/admin/leads') {
            markPlatformSectionViewed('leads');
        } else if (location.pathname === '/admin/support') {
            markPlatformSectionViewed('support');
        } else if (location.pathname === '/admin/queue') {
            markPlatformSectionViewed('queue');
        }
    }, [location.pathname, platformOnly, markPlatformSectionViewed]);

    const refreshTenant = useCallback(async () => {
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

    const refreshPlatform = useCallback(async () => {
        if (!user || !platformOnly) {
            setPlatformCounts({ leads: 0, support: 0, queue: 0 });
            return;
        }
        try {
            const { data } = await api.get('/system/badge-counts');
            setPlatformCounts({
                leads: Number(data.leads || 0),
                support: Number(data.support || 0),
                queue: Number(data.queue || 0),
            });
        } catch {
            // keep current count on transient failures
        }
    }, [user, platformOnly]);

    const refresh = useCallback(async () => {
        if (platformOnly) {
            await refreshPlatform();
        } else {
            await refreshTenant();
        }
    }, [platformOnly, refreshPlatform, refreshTenant]);

    useEffect(() => {
        if (platformOnly) {
            refreshPlatform();
            const intervalMs = window.Echo ? 60000 : 30000;
            const timer = window.setInterval(refreshPlatform, intervalMs);
            return () => window.clearInterval(timer);
        }

        refreshTenant();
        const intervalMs = window.Echo ? 60000 : 30000;
        const timer = window.setInterval(refreshTenant, intervalMs);
        return () => window.clearInterval(timer);
    }, [refreshTenant, refreshPlatform, platformOnly]);

    // Tenant real-time notifications via Reverb / Echo (user.{id})
    useEffect(() => {
        if (!user || platformOnly || !window.Echo) return undefined;
        const channel = window.Echo.private(`user.${user.id}`);
        const handler = (event) => {
            setUnreadCount((count) => count + 1);
            toast.info(describeNotification(t, event.type, event.data ?? {}, event.actor?.name ?? null));
            refreshTenant();
        };
        channel.listen('.notification.sent', handler);
        const stopReconnect = onRealtimeReconnect(refreshTenant);

        return () => {
            channel.stopListening('.notification.sent', handler);
            stopReconnect();
            window.Echo.leave(`user.${user.id}`);
        };
    }, [user, platformOnly, toast, t, refreshTenant]);

    // Platform (superadmin) real-time notifications via Reverb / Echo (platform.admin)
    useEffect(() => {
        if (!user || !platformOnly || !window.Echo) return undefined;
        const channel = window.Echo.private('platform.admin');

        const onLeadReceived = (event) => {
            setPlatformCounts((counts) => ({ ...counts, leads: counts.leads + 1 }));
            setViewedSections((prev) => {
                const next = { ...prev, leads: false };
                try {
                    sessionStorage.setItem(STORAGE_KEY_VIEWED, JSON.stringify(next));
                } catch {
                    // ignore
                }
                return next;
            });
            const companySuffix = event.company ? ` (${event.company})` : '';
            const newAlert = {
                id: `lead-${event.id || Date.now()}`,
                type: 'leads',
                title: t('notifications.leadReceived', { defaultValue: 'New lead received' }),
                message: `${event.name}${companySuffix}`,
                to: '/admin/leads',
                time: event.created_at || new Date().toISOString(),
                read: false,
            };
            setPlatformAlerts((prev) => {
                const next = [newAlert, ...prev.filter((a) => a.id !== newAlert.id)].slice(0, 50);
                try {
                    sessionStorage.setItem(STORAGE_KEY_ALERTS, JSON.stringify(next));
                } catch {
                    // ignore
                }
                return next;
            });
            toast.info(t('notifications.leadReceived', {
                defaultValue: `New lead received from ${event.name}${companySuffix}`,
            }));
            refreshPlatform();
        };

        const onSupportTicketAlert = (event) => {
            setPlatformCounts((counts) => ({ ...counts, support: counts.support + 1 }));
            setViewedSections((prev) => {
                const next = { ...prev, support: false };
                try {
                    sessionStorage.setItem(STORAGE_KEY_VIEWED, JSON.stringify(next));
                } catch {
                    // ignore
                }
                return next;
            });
            const actionLabel = event.action === 'replied' ? 'reply' : 'opened';
            const newAlert = {
                id: `support-${event.id || Date.now()}`,
                type: 'support',
                title: t('notifications.supportTicketAlert', { defaultValue: `Support ticket ${actionLabel}` }),
                message: `${event.subject} (${event.tenant_name})`,
                to: '/admin/support',
                time: event.last_activity_at || new Date().toISOString(),
                read: false,
            };
            setPlatformAlerts((prev) => {
                const next = [newAlert, ...prev.filter((a) => a.id !== newAlert.id)].slice(0, 50);
                try {
                    sessionStorage.setItem(STORAGE_KEY_ALERTS, JSON.stringify(next));
                } catch {
                    // ignore
                }
                return next;
            });
            toast.info(t('notifications.supportTicketAlert', {
                defaultValue: `Support ticket ${actionLabel}: ${event.subject} (${event.tenant_name})`,
            }));
            refreshPlatform();
        };

        const onQueueJobFailed = (event) => {
            setPlatformCounts((counts) => ({ ...counts, queue: counts.queue + 1 }));
            setViewedSections((prev) => {
                const next = { ...prev, queue: false };
                try {
                    sessionStorage.setItem(STORAGE_KEY_VIEWED, JSON.stringify(next));
                } catch {
                    // ignore
                }
                return next;
            });
            const newAlert = {
                id: `queue-${Date.now()}`,
                type: 'queue',
                title: t('notifications.queueJobFailed', { defaultValue: 'Queue job failed' }),
                message: event.job,
                to: '/admin/queue',
                time: event.failed_at || new Date().toISOString(),
                read: false,
            };
            setPlatformAlerts((prev) => {
                const next = [newAlert, ...prev].slice(0, 50);
                try {
                    sessionStorage.setItem(STORAGE_KEY_ALERTS, JSON.stringify(next));
                } catch {
                    // ignore
                }
                return next;
            });
            toast.error(t('notifications.queueJobFailed', {
                defaultValue: `Queue job failed: ${event.job}`,
            }));
            refreshPlatform();
        };

        channel.listen('.lead.received', onLeadReceived);
        channel.listen('.support.ticket.alert', onSupportTicketAlert);
        channel.listen('.queue.job.failed', onQueueJobFailed);

        const stopReconnect = onRealtimeReconnect(refreshPlatform);

        return () => {
            channel.stopListening('.lead.received', onLeadReceived);
            channel.stopListening('.support.ticket.alert', onSupportTicketAlert);
            channel.stopListening('.queue.job.failed', onQueueJobFailed);
            stopReconnect();
            window.Echo.leave('platform.admin');
        };
    }, [user, platformOnly, toast, t, refreshPlatform]);

    const markAllRead = useCallback(async () => {
        await api.post('/notifications/mark-all-read');
        setUnreadCount(0);
    }, []);

    const markRead = useCallback(async (id) => {
        await api.post(`/notifications/${id}/read`);
        setUnreadCount((count) => Math.max(0, count - 1));
    }, []);

    const platformUnreadCount = useMemo(() => {
        return platformAlerts.filter((alert) => !alert.read).length;
    }, [platformAlerts]);

    const effectivePlatformCounts = useMemo(() => {
        return {
            leads: viewedSections.leads ? 0 : platformCounts.leads,
            support: viewedSections.support ? 0 : platformCounts.support,
            queue: viewedSections.queue ? 0 : platformCounts.queue,
        };
    }, [viewedSections, platformCounts]);

    const value = useMemo(
        () => ({
            unreadCount,
            inboxUnread,
            platformCounts,
            effectivePlatformCounts,
            platformAlerts,
            platformUnreadCount,
            markPlatformSectionViewed,
            markPlatformAlertRead,
            markAllPlatformAlertsRead,
            refresh,
            refreshTenant,
            refreshPlatform,
            markAllRead,
            markRead,
        }),
        [
            unreadCount,
            inboxUnread,
            platformCounts,
            effectivePlatformCounts,
            platformAlerts,
            platformUnreadCount,
            markPlatformSectionViewed,
            markPlatformAlertRead,
            markAllPlatformAlertsRead,
            refresh,
            refreshTenant,
            refreshPlatform,
            markAllRead,
            markRead,
        ],
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