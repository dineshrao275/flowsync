import { useEffect, useState } from 'react';
import UserChip from '../ui/UserChip';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../ui/Alert';
import ContentSkeleton from '../ui/ContentSkeleton';
import Timestamp from '../ui/Timestamp';

const ACTION_KEYS = {
    'task.created': 'created',
    'task.updated': 'updated',
    'task.deleted': 'deleted',
    'task.moved': 'moved',
    'task.commented': 'commented',
    'task.attachment_created': 'attachmentCreated',
    'task.attachment_deleted': 'attachmentDeleted',
    'task.dependency_created': 'dependencyCreated',
    'task.dependency_deleted': 'dependencyDeleted',
    'task.checklist_item_added': 'checklistAdded',
    'task.checklist_item_checked': 'checklistChecked',
    'task.checklist_item_unchecked': 'checklistUnchecked',
    'task.checklist_item_removed': 'checklistRemoved',
    'task.sla_breached': 'slaBreached',
    'task.move_requested': 'moveRequested',
    'task.move_approved': 'moveApproved',
    'task.move_rejected': 'moveRejected',
    'task.vcs_event': 'vcsEvent',
};

import StatusBadge from '../ui/StatusBadge';
import TaskBadge from '../ui/TaskBadge';

function describe(t, action, data, task, projectId) {
    const label = (status) => (typeof status === 'string' ? status : status?.name ?? '');

    if (action === 'task.moved' && data?.from_status && data?.to_status) {
        return (
            <span className="inline-flex flex-wrap items-center gap-1.5 align-middle">
                {task?.key && (
                    <TaskBadge taskKey={task.key} taskId={task.id} projectId={projectId} />
                )}
                <span className="text-gray-600 dark:text-gray-400">{t('tms.activity.movedAction', { defaultValue: 'moved from' })}</span>
                <StatusBadge status={label(data.from_status)} />
                <span className="text-gray-600 dark:text-gray-400">{t('tms.activity.toAction', { defaultValue: 'to' })}</span>
                <StatusBadge status={label(data.to_status)} />
            </span>
        );
    }
    if (action === 'task.commented' && data?.snippet) {
        return (
            <span className="align-middle">
                <span className="text-gray-600 dark:text-gray-400">{t('tms.activity.commented', { defaultValue: 'commented' })}:</span>{' '}
                <span className="inline-block rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs italic text-gray-700 dark:border-slate-800 dark:bg-slate-800 dark:text-gray-300">
                    &ldquo;{data.snippet}&rdquo;
                </span>
            </span>
        );
    }
    if (action === 'task.dependency_created' || action === 'task.dependency_deleted') {
        const isCreated = action === 'task.dependency_created';
        return (
            <span className="inline-flex flex-wrap items-center gap-1.5 align-middle">
                <span className="text-gray-600 dark:text-gray-400">
                    {isCreated ? t('tms.activity.depAdded', { defaultValue: 'added dependency on' }) : t('tms.activity.depRemoved', { defaultValue: 'removed dependency on' })}
                </span>
                <TaskBadge
                    taskId={data?.depends_on_task_id}
                    taskKey={data?.depends_on_task_key || `#${data?.depends_on_task_id}`}
                    projectId={projectId}
                />
            </span>
        );
    }
    if (action === 'task.checklist_item_added') {
        return (
            <span className="align-middle">
                <span className="text-gray-600 dark:text-gray-400">{t('tms.activity.checklistAdded', { defaultValue: 'added checklist item' })}</span>{' '}
                <strong className="font-semibold text-gray-900 dark:text-gray-100">&ldquo;{data?.title ?? ''}&rdquo;</strong>
            </span>
        );
    }
    if (action === 'task.checklist_item_checked') {
        return (
            <span className="align-middle">
                <span className="text-gray-600 dark:text-gray-400">{t('tms.activity.checklistChecked', { defaultValue: 'completed checklist item' })}</span>{' '}
                <strong className="font-semibold text-gray-900 dark:text-gray-100">&ldquo;{data?.title ?? ''}&rdquo;</strong>
            </span>
        );
    }
    if (action === 'task.checklist_item_unchecked') {
        return (
            <span className="align-middle">
                <span className="text-gray-600 dark:text-gray-400">{t('tms.activity.checklistUnchecked', { defaultValue: 'unchecked checklist item' })}</span>{' '}
                <strong className="font-semibold text-gray-900 dark:text-gray-100">&ldquo;{data?.title ?? ''}&rdquo;</strong>
            </span>
        );
    }
    if (action === 'task.checklist_item_removed') {
        return (
            <span className="align-middle">
                <span className="text-gray-600 dark:text-gray-400">{t('tms.activity.checklistRemoved', { defaultValue: 'removed checklist item' })}</span>{' '}
                <strong className="font-semibold text-gray-900 dark:text-gray-100">&ldquo;{data?.title ?? ''}&rdquo;</strong>
            </span>
        );
    }
    if (action === 'task.vcs_event') {
        return (
            <span className="align-middle">
                {t('tms.activity.vcsLine', {
                    kind: t(`tms.activity.vcsKind.${data?.kind}`, { defaultValue: data?.kind ?? '' }),
                    state: data?.state ?? '',
                    title: data?.title ?? '',
                })}
            </span>
        );
    }
    if (action === 'task.sla_breached') {
        return (
            <span className="inline-flex items-center gap-1.5 align-middle">
                <StatusBadge status={t('tms.activity.slaBreachedBadge', { defaultValue: 'SLA Breached' })} tone="danger" />
                <span className="text-xs text-gray-500">{t('tms.activity.slaLine', { sla: data?.sla ?? '', hours: data?.target_hours ?? '' })}</span>
            </span>
        );
    }
    const key = ACTION_KEYS[action];
    return key ? t(`tms.activity.${key}`) : action;
}

export default function ActivityFeed({ task, projectId }) {
    const { t } = useTranslation();
    const [activities, setActivities] = useState(null);
    const [error, setError] = useState(null);

    function fetchActivities() {
        api.get(`/projects/${projectId}/tasks/${task.id}/activities`)
            .then(({ data }) => {
                setActivities(data.activities);
                setError(null);
            })
            .catch(() => setError(t('tms.activity.loadFailed')));
    }

    useEffect(() => {
        fetchActivities();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId, task.id]);

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!activities) {
        return (
            <ContentSkeleton size="md" />
        );
    }

    if (activities.length === 0) {
        return <p className="py-6 text-center text-sm text-gray-400">{t('tms.activity.empty')}</p>;
    }

    return (
        <ol className="space-y-1 border-l border-gray-200 pl-4">
            {activities.map((activity) => (
                <li key={activity.id} className="relative py-1.5">
                    <span className="absolute -left-[21px] top-3 h-2.5 w-2.5 rounded-full border-2 border-white bg-[var(--accent)] shadow" />
                    <p className="text-sm text-gray-700">
                        <UserChip user={activity.actor} name={activity.actor?.name ?? t('tms.activity.someone')} className="align-middle" nameClassName="font-medium text-gray-900" />{' '}
                        {describe(t, activity.action, activity.data, task, projectId)}
                    </p>
                    <p className="text-xs text-gray-400"><Timestamp date={activity.created_at} /></p>
                </li>
            ))}
        </ol>
    );
}