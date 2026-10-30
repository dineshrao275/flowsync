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
const STORAGE_KEY_SEEN_COUNTS = 'flowsync_seen_section_counts';

function getUserStorageKey(baseKey, userId) {
    return userId ? `${baseKey}_${userId}` : baseKey;
}

function readStorageJson(key, userId, fallback) {
    try {
        const userSpecific = localStorage.getItem(getUserStorageKey(key, userId));
        if (userSpecific !== null) return JSON.parse(userSpecific);
        const legacyLocal = localStorage.getItem(key);
        if (legacyLocal !== null) return JSON.parse(legacyLocal);
        const legacySession = sessionStorage.getItem(key);
        if (legacySession !== null) return JSON.parse(legacySession);
    } catch {
        // ignore
    }
    return fallback;
}

export function NotificationProvider({ children }) {
    const { t } = useTranslation();
    const { user } = useAuth();
    const location = useLocation();
    const toast = useToast();
    const [unreadCount, setUnreadCount] = useState(0);
    const [inboxUnread, setInboxUnread] = useState(0);
    const [platformCounts, setPlatformCounts] = useState({ leads: 0, support: 0, queue: 0 });

    const [viewedSections, setViewedSections] = useState(() =>
        readStorageJson(STORAGE_KEY_VIEWED, user?.id, {}),
    );

    const [seenCounts, setSeenCounts] = useState(() =>
        readStorageJson(STORAGE_KEY_SEEN_COUNTS, user?.id, {}),
    );

    const [platformAlerts, setPlatformAlerts] = useState(() =>
        readStorageJson(STORAGE_KEY_ALERTS, user?.id, []),
    );

    // Synchronize storage when user authentication resolves or changes
    useEffect(() => {
        if (!user?.id) {
            setViewedSections({});
            setSeenCounts({});
            setPlatformAlerts([]);
            return;
        }

        setViewedSections(readStorageJson(STORAGE_KEY_VIEWED, user.id, {}));
        setSeenCounts(readStorageJson(STORAGE_KEY_SEEN_COUNTS, user.id, {}));
        setPlatformAlerts(readStorageJson(STORAGE_KEY_ALERTS, user.id, []));
    }, [user?.id]);

    // A non-impersonating super admin has no tenant database: personal
    // notifications live in the tenant DB only, but they monitor platform-wide
    // events (marketing leads, support tickets, background queue failures).
    const platformOnly = Boolean(user?.is_super_admin && !user?.impersonating);

    const markSectionViewed = useCallback((sectionKey) => {
        const userId = user?.id;

        setViewedSections((prev) => {
            const next = { ...prev, [sectionKey]: true };
            if (userId) {
                try {
                    localStorage.setItem(getUserStorageKey(STORAGE_KEY_VIEWED, userId), JSON.stringify(next));
                } catch {
                    // ignore
                }
            }
            return next;
        });

        setSeenCounts((prev) => {
            const currentCount = sectionKey === 'inbox'
                ? inboxUnread
                : (platformCounts[sectionKey] ?? 0);
            const next = { ...prev, [sectionKey]: currentCount };
            if (userId) {
                try {
                    localStorage.setItem(getUserStorageKey(STORAGE_KEY_SEEN_COUNTS, userId), JSON.stringify(next));
                } catch {
                    // ignore
                }
            }
            return next;
        });

        if (sectionKey !== 'inbox') {
            setPlatformAlerts((prev) => {
                const next = prev.map((alert) =>
                    alert.type === sectionKey ? { ...alert, read: true } : alert,
                );
                if (userId) {
                    try {
                        localStorage.setItem(getUserStorageKey(STORAGE_KEY_ALERTS, userId), JSON.stringify(next));
                    } catch {
                        // ignore
                    }
                }
                return next;
            });
        }
    }, [user?.id, platformCounts, inboxUnread]);

    const markPlatformSectionViewed = markSectionViewed;

    const markPlatformAlertRead = useCallback((alertId) => {
        let alertType = null;
        const userId = user?.id;
        setPlatformAlerts((prev) => {
            const target = prev.find((a) => a.id === alertId);
            if (target) alertType = target.type;
            const next = prev.map((alert) =>
                alert.id === alertId ? { ...alert, read: true } : alert,
            );
            if (userId) {
                try {
                    localStorage.setItem(getUserStorageKey(STORAGE_KEY_ALERTS, userId), JSON.stringify(next));
                } catch {
                    // ignore
                }
            }
            return next;
        });

        if (alertType) {
            markSectionViewed(alertType);
        }
    }, [user?.id, markSectionViewed]);

    const markAllPlatformAlertsRead = useCallback(() => {
        const userId = user?.id;
        setPlatformAlerts((prev) => {
            const next = prev.map((alert) => ({ ...alert, read: true }));
            if (userId) {
                try {
                    localStorage.setItem(getUserStorageKey(STORAGE_KEY_ALERTS, userId), JSON.stringify(next));
                } catch {
                    // ignore
                }
            }
            return next;
        });

        const allViewed = { leads: true, support: true, queue: true, inbox: true };
        setViewedSections((prev) => ({ ...prev, ...allViewed }));
        const allCounts = {
            leads: platformCounts.leads,
            support: platformCounts.support,
            queue: platformCounts.queue,
            inbox: inboxUnread,
        };
        setSeenCounts((prev) => ({ ...prev, ...allCounts }));

        if (userId) {
            try {
                localStorage.setItem(getUserStorageKey(STORAGE_KEY_VIEWED, userId), JSON.stringify(allViewed));
                localStorage.setItem(getUserStorageKey(STORAGE_KEY_SEEN_COUNTS, userId), JSON.stringify(allCounts));
            } catch {
                // ignore
            }
        }
    }, [user?.id, platformCounts, inboxUnread]);

    // Dismiss badge as soon as the user navigates to the corresponding page to view the info
    useEffect(() => {
        const path = location.pathname;
        if (platformOnly) {
            if (path === '/admin/leads' || path.startsWith('/admin/leads/')) {
                markSectionViewed('leads');
            } else if (path === '/admin/support' || path.startsWith('/admin/support/')) {
                markSectionViewed('support');
            } else if (path === '/admin/queue' || path.startsWith('/admin/queue/')) {
                markSectionViewed('queue');
            }
        } else {
            if (path === '/hrms/inbox' || path.startsWith('/hrms/inbox/')) {
                markSectionViewed('inbox');
            }
        }
    }, [location.pathname, platformOnly, markSectionViewed]);

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
                if (user?.id) {
                    try {
                        localStorage.setItem(getUserStorageKey(STORAGE_KEY_VIEWED, user.id), JSON.stringify(next));
                    } catch {
                        // ignore
                    }
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
                if (user?.id) {
                    try {
                        localStorage.setItem(getUserStorageKey(STORAGE_KEY_ALERTS, user.id), JSON.stringify(next));
                    } catch {
                        // ignore
                    }
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
                if (user?.id) {
                    try {
                        localStorage.setItem(getUserStorageKey(STORAGE_KEY_VIEWED, user.id), JSON.stringify(next));
                    } catch {
                        // ignore
                    }
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
                if (user?.id) {
                    try {
                        localStorage.setItem(getUserStorageKey(STORAGE_KEY_ALERTS, user.id), JSON.stringify(next));
                    } catch {
                        // ignore
                    }
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
                if (user?.id) {
                    try {
                        localStorage.setItem(getUserStorageKey(STORAGE_KEY_VIEWED, user.id), JSON.stringify(next));
                    } catch {
                        // ignore
                    }
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
                if (user?.id) {
                    try {
                        localStorage.setItem(getUserStorageKey(STORAGE_KEY_ALERTS, user.id), JSON.stringify(next));
                    } catch {
                        // ignore
                    }
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

    const calculateEffective = useCallback((current, seen, viewed) => {
        if (viewed) {
            if (seen !== undefined && current <= seen) {
                return 0;
            }
            if (seen === undefined) {
                return 0;
            }
            return Math.max(0, current - seen);
        }
        if (seen !== undefined) {
            return Math.max(0, current - seen);
        }
        return current;
    }, []);

    const effectivePlatformCounts = useMemo(() => {
        return {
            leads: calculateEffective(platformCounts.leads ?? 0, seenCounts.leads, viewedSections.leads),
            support: calculateEffective(platformCounts.support ?? 0, seenCounts.support, viewedSections.support),
            queue: calculateEffective(platformCounts.queue ?? 0, seenCounts.queue, viewedSections.queue),
        };
    }, [viewedSections, platformCounts, seenCounts, calculateEffective]);

    const effectiveInboxUnread = useMemo(() => {
        return calculateEffective(inboxUnread ?? 0, seenCounts.inbox, viewedSections.inbox);
    }, [viewedSections.inbox, inboxUnread, seenCounts.inbox, calculateEffective]);

    const value = useMemo(
        () => ({
            unreadCount,
            inboxUnread,
            effectiveInboxUnread,
            platformCounts,
            effectivePlatformCounts,
            platformAlerts,
            platformUnreadCount,
            markSectionViewed,
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
            effectiveInboxUnread,
            platformCounts,
            effectivePlatformCounts,
            platformAlerts,
            platformUnreadCount,
            markSectionViewed,
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