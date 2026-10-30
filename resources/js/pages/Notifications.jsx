import { useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Avatar from '../components/ui/Avatar';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Pagination from '../components/ui/Pagination';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import ErrorState from '../components/ui/ErrorState';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useNotifications } from '../context/NotificationContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { describeNotification, notificationHref } from '../utils/notifications';
import { projectUrl } from '../utils/deepLinks';
import usePageTitle from '../hooks/usePageTitle';
import { usePaginated } from '../hooks/useResource';
import Timestamp from '../components/ui/Timestamp';
import UserTooltip from '../components/ui/UserTooltip';
import TaskBadge from '../components/ui/TaskBadge';
import HighlightedContent from '../components/ui/HighlightedContent';

export default function Notifications() {
    const { t } = useTranslation();
    usePageTitle(t('notifications.pageTitle'));
    const { markAllRead, markRead } = useNotifications();
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { data, error, reload, page, setPage } = usePaginated((p, signal) =>
        api.get('/notifications', { params: { page: p, per_page: 20 }, signal }).then(({ data: response }) => response),
    );

    useEffect(() => {
        setCrumbs([{ label: t('notifications.pageTitle') }]);
    }, [setCrumbs, t]);

    function openNotification(notification) {
        markRead(notification.id);
        navigate(notificationHref(notification.data ?? {}, notification.type));
    }

    const notifications = data?.notifications ?? null;
    const pagination = data?.pagination ?? null;

    return (
        <div className="mx-auto max-w-5xl space-y-4">
            <div className="flex items-center justify-between">
                <h2 className="text-xl font-semibold text-gray-900">{t('notifications.pageTitle')}</h2>
                {data?.unread_count > 0 && (
                    <Button variant="secondary" onClick={markAllRead}>
                        {t('notifications.markAllRead')}
                    </Button>
                )}
            </div>

            {error && data == null && <ErrorState title={t('notifications.loadFailed')} onRetry={reload} />}

            <Table>
                <thead>
                    <tr>
                        <Th>{t('notifications.thNotification')}</Th>
                        <Th>{t('notifications.thActor')}</Th>
                        <Th>{t('notifications.thTaskProject')}</Th>
                        <Th align="right">{t('notifications.thTime')}</Th>
                        <Th align="right">{t('notifications.thRead')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {notifications === null ? (
                        <tr>
                            <Td colSpan={5} className="py-12 text-center">
                                <ContentSkeleton size="sm" />
                            </Td>
                        </tr>
                    ) : notifications.length === 0 ? (
                        <TableEmpty colSpan={5}>{t('notifications.empty')}</TableEmpty>
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
                                        <div className="mt-1 font-medium text-gray-800">
                                            <HighlightedContent
                                                content={describeNotification(
                                                    t,
                                                    notification.type,
                                                    payload,
                                                    notification.actor?.name ?? null,
                                                )}
                                                projectId={payload.project_id}
                                            />
                                        </div>
                                    </Td>
                                    <Td>
                                        <UserTooltip user={notification.actor} name={notification.actor?.name}>
                                            <span className="flex items-center gap-2 cursor-pointer">
                                                <Avatar name={notification.actor?.name} size="sm" />
                                                <span className="truncate">
                                                    {notification.actor?.name ?? t('notifications.someone')}
                                                    {notification.actor?.email && (
                                                        <span className="block truncate text-xs text-gray-400">{notification.actor.email}</span>
                                                    )}
                                                </span>
                                            </span>
                                        </UserTooltip>
                                    </Td>
                                    <Td className="whitespace-nowrap">
                                        {payload.project_id && payload.key ? (
                                            <span className="inline-flex items-center gap-1.5">
                                                <TaskBadge taskKey={payload.key} projectId={payload.project_id} title={payload.title} />
                                                <span className="text-xs text-gray-500">· {payload.project_name ?? payload.title}</span>
                                            </span>
                                        ) : payload.project_id ? (
                                            <Link
                                                to={projectUrl(payload.project_id)}
                                                className="font-medium text-[var(--accent-soft-text)] hover:text-[var(--accent)]"
                                            >
                                                {payload.project_name ?? payload.title}
                                            </Link>
                                        ) : (
                                            <span className="text-gray-400">{payload.project_name ?? payload.title ?? '—'}</span>
                                        )}
                                    </Td>
                                    <Td align="right" className="whitespace-nowrap text-xs text-gray-400">
                                        <Timestamp date={notification.created_at} />
                                    </Td>
                                    <Td align="right" className="whitespace-nowrap">
                                        {notification.read_at ? (
                                            <span className="text-xs text-gray-400">{t('notifications.read')}</span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-[var(--accent-soft-text)]">
                                                <span className="h-2 w-2 rounded-full bg-[var(--accent)]" />
                                                {t('notifications.unread')}
                                            </span>
                                        )}
                                    </Td>
                                </tr>
                            );
                        })
                    )}
                </tbody>
            </Table>

            {pagination && <Pagination page={page} pages={pagination.last_page} onChange={setPage} />}
        </div>
    );
}
