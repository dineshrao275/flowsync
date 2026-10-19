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

function describe(t, action, data) {
    if (action === 'task.moved' && data?.from_status && data?.to_status) {
        // Stored as plain names by the server; older rows / other writers may carry {id, name}.
        const label = (status) => (typeof status === 'string' ? status : status?.name ?? '');
        return t('tms.activity.movedFromTo', { from: label(data.from_status), to: label(data.to_status) });
    }
    if (action === 'task.commented' && data?.snippet) {
        return t('tms.activity.commentedSnippet', { snippet: data.snippet });
    }
    if (action === 'task.dependency_created' || action === 'task.dependency_deleted') {
        return t('tms.activity.dependencyOn', { id: data?.depends_on_task_id });
    }
    if (action === 'task.checklist_item_added') {
        return t('tms.activity.checklistAddedTitle', { title: data?.title ?? '' });
    }
    if (action === 'task.checklist_item_checked') {
        return t('tms.activity.checklistCheckedTitle', { title: data?.title ?? '' });
    }
    if (action === 'task.checklist_item_unchecked') {
        return t('tms.activity.checklistUncheckedTitle', { title: data?.title ?? '' });
    }
    if (action === 'task.checklist_item_removed') {
        return t('tms.activity.checklistRemovedTitle', { title: data?.title ?? '' });
    }
    if (action === 'task.vcs_event') {
        return t('tms.activity.vcsLine', { kind: t(`tms.activity.vcsKind.${data?.kind}`, { defaultValue: data?.kind ?? '' }), state: data?.state ?? '', title: data?.title ?? '' });
    }
    if (action === 'task.sla_breached') {
        return t('tms.activity.slaLine', { sla: data?.sla ?? '', hours: data?.target_hours ?? '' });
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
                        {describe(t, activity.action, activity.data)}
                    </p>
                    <p className="text-xs text-gray-400"><Timestamp date={activity.created_at} /></p>
                </li>
            ))}
        </ol>
    );
}