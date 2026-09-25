import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../services/api';
import Avatar from '../components/ui/Avatar';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Pagination from '../components/ui/Pagination';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useNotifications } from '../context/NotificationContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { describeNotification, notificationHref, timeAgo } from '../utils/notifications';
import { projectUrl, taskUrl } from '../utils/deepLinks';
import usePageTitle from '../hooks/usePageTitle';

export default function Notifications() {
    usePageTitle('Notifications');
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
        <div className="mx-auto max-w-5xl space-y-4">
            <div className="flex items-center justify-between">
                <h2 className="text-xl font-semibold text-gray-900">Notifications</h2>
                {data?.unread_count > 0 && (
                    <Button variant="secondary" onClick={markAllRead}>
                        Mark all as read
                    </Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            <Table>
                <thead>
                    <tr>
                        <Th>Notification</Th>
                        <Th>Actor</Th>
                        <Th>Task / project</Th>
                        <Th align="right">Time</Th>
                        <Th align="right">Read</Th>
                    </tr>
                </thead>
                <tbody>
                    {notifications === null ? (
                        <tr>
                            <Td colSpan={5} className="py-12 text-center">
                                <Spinner />
                            </Td>
                        </tr>
                    ) : notifications.length === 0 ? (
                        <TableEmpty colSpan={5}>No notifications yet.</TableEmpty>
                    ) : (
                        notifications.map((notification, index) => {
                            const payload = notification.data ?? {};

                            return (
                                <tr
                                    key={notification.id}
                                    onClick={() => openNotification(notification)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') openNotification(notification);
                                    }}
                                    tabIndex={0}
                                    className={`animate-fade-in cursor-pointer transition-colors duration-150 hover:bg-gray-50 ${
                                        notification.read_at ? 'opacity-70' : ''
                                    }`}
                                    style={{ animationDelay: `${index * 30}ms` }}
                                >
                                    <Td>
                                        <Badge>{notification.type}</Badge>
                                        <span className="mt-1 block font-medium text-gray-800">
                                            {describeNotification(
                                                notification.type,
                                                payload,
                                                notification.actor?.name ?? 'Someone',
                                            )}
                                        </span>
                                    </Td>
                                    <Td>
                                        <span className="flex items-center gap-2">
                                            <Avatar name={notification.actor?.name} size="sm" />
                                            <span className="truncate">
                                                {notification.actor?.name ?? 'Someone'}
                                                {notification.actor?.email && (
                                                    <span className="block truncate text-xs text-gray-400">{notification.actor.email}</span>
                                                )}
                                            </span>
                                        </span>
                                    </Td>
                                    <Td className="whitespace-nowrap">
                                        {payload.project_id && payload.key ? (
                                            <Link
                                                to={taskUrl(payload.project_id, payload.key)}
                                                className="font-medium text-indigo-600 hover:text-indigo-800"
                                            >
                                                {payload.key} · {payload.project_name ?? payload.title}
                                            </Link>
                                        ) : payload.project_id ? (
                                            <Link
                                                to={projectUrl(payload.project_id)}
                                                className="font-medium text-indigo-600 hover:text-indigo-800"
                                            >
                                                {payload.project_name ?? payload.title}
                                            </Link>
                                        ) : (
                                            <span className="text-gray-400">{payload.project_name ?? payload.title ?? '—'}</span>
                                        )}
                                    </Td>
                                    <Td align="right" className="whitespace-nowrap text-xs text-gray-400">
                                        {timeAgo(notification.created_at)}
                                    </Td>
                                    <Td align="right" className="whitespace-nowrap">
                                        {notification.read_at ? (
                                            <span className="text-xs text-gray-400">Read</span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600">
                                                <span className="h-2 w-2 rounded-full bg-[var(--accent)]" />
                                                Unread
                                            </span>
                                        )}
                                    </Td>
                                </tr>
                            );
                        })
                    )}
                </tbody>
            </Table>

            <Pagination page={page} pages={pagination.last_page} onChange={setPage} />
        </div>
    );
}
