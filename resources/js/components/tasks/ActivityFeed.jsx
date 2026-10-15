import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
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
};

function describe(t, action, data) {
    if (action === 'task.moved' && data?.from_status && data?.to_status) {
        return t('tms.activity.movedFromTo', { from: data.from_status.name, to: data.to_status.name });
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
            <div className="flex justify-center py-10">
                <Spinner />
            </div>
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
                        <span className="font-medium text-gray-900">{activity.actor?.name ?? t('tms.activity.someone')}</span>{' '}
                        {describe(t, activity.action, activity.data)}
                    </p>
                    <p className="text-xs text-gray-400"><Timestamp date={activity.created_at} /></p>
                </li>
            ))}
        </ol>
    );
}