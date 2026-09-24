import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { useNotifications } from '../context/NotificationContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { describeNotification, notificationHref, timeAgo } from '../utils/notifications';

export default function Notifications() {
    const { markAllRead, markRead } = useNotifications();
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const [data, setData] = useState(null);
    const [page, setPage] = useState(1);
    const [error, setError] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'Notifications' }]);
    }, [setCrumbs]);

    const load = useCallback(
        (targetPage) => {
            setError(null);
            api.get('/notifications', { params: { page: targetPage, per_page: 20 } })
                .then(({ data: response }) => setData(response))
                .catch(() => setError('Unable to load notifications.'));
        },
        [],
    );

    useEffect(() => {
        load(page);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page]);

    function openNotification(notification) {
        markRead(notification.id);
        navigate(notificationHref(notification.data ?? {}, notification.type));
    }

    const notifications = data?.notifications ?? null;
    const pagination = data?.pagination ?? null;

    return (
        <div className="mx-auto max-w-3xl space-y-4">
            <div className="flex items-center justify-between">
                <h2 className="text-xl font-semibold text-gray-900">Notifications</h2>
                {data?.unread_count > 0 && (
                    <Button variant="secondary" onClick={markAllRead}>
                        Mark all as read
                    </Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            <Card>
                {notifications === null ? (
                    <div className="flex justify-center py-12">
                        <Spinner />
                    </div>
                ) : notifications.length === 0 ? (
                    <p className="py-12 text-center text-sm text-gray-400">No notifications yet.</p>
                ) : (
                    <ul className="divide-y divide-gray-50">
                        {notifications.map((notification) => (
                            <li key={notification.id}>
                                <button
                                    onClick={() => openNotification(notification)}
                                    className={`flex w-full items-start gap-3 px-5 py-4 text-left transition hover:bg-gray-50 ${
                                        notification.read_at ? 'opacity-70' : ''
                                    }`}
                                >
                                    <span
                                        className="mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white"
                                        style={{ backgroundColor: 'var(--accent)' }}
                                    >
                                        {notification.actor?.name?.charAt(0).toUpperCase() ?? '?'}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-medium text-gray-800">
                                            {describeNotification(
                                                notification.type,
                                                notification.data ?? {},
                                                notification.actor?.name ?? 'Someone',
                                            )}
                                        </span>
                                        <span className="mt-0.5 flex items-center gap-2 text-xs text-gray-400">
                                            {notification.data?.project_name && <span>{notification.data.project_name}</span>}
                                            <span>{timeAgo(notification.created_at)}</span>
                                        </span>
                                    </span>
                                    {!notification.read_at && <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-indigo-500" />}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {pagination && pagination.last_page > 1 && (
                <div className="flex items-center justify-between">
                    <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        Previous
                    </Button>
                    <span className="text-sm text-gray-500">
                        Page {pagination.current_page} of {pagination.last_page}
                    </span>
                    <Button variant="secondary" disabled={page >= pagination.last_page} onClick={() => setPage(page + 1)}>
                        Next
                    </Button>
                </div>
            )}
        </div>
    );
}