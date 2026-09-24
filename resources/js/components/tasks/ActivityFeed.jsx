import { useEffect, useState } from 'react';
import api from '../../services/api';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';

const ACTION_LABELS = {
    'task.created': 'created the task',
    'task.updated': 'updated the task',
    'task.deleted': 'deleted the task',
    'task.moved': 'moved the task',
    'task.commented': 'commented',
    'task.attachment_created': 'uploaded an attachment',
    'task.attachment_deleted': 'deleted an attachment',
    'task.dependency_created': 'added a dependency',
    'task.dependency_deleted': 'removed a dependency',
};

function describe(action, data) {
    if (action === 'task.moved' && data?.from_status && data?.to_status) {
        return `${ACTION_LABELS[action] ?? action} from "${data.from_status.name}" to "${data.to_status.name}"`;
    }
    if (action === 'task.commented' && data?.snippet) {
        return `commented: "${data.snippet}"`;
    }
    if (action === 'task.dependency_created' || action === 'task.dependency_deleted') {
        return `a dependency on task #${data?.depends_on_task_id}`;
    }
    return ACTION_LABELS[action] ?? action;
}

export default function ActivityFeed({ task, projectId }) {
    const [activities, setActivities] = useState(null);
    const [error, setError] = useState(null);

    function fetchActivities() {
        api.get(`/projects/${projectId}/tasks/${task.id}/activities`)
            .then(({ data }) => {
                setActivities(data.activities);
                setError(null);
            })
            .catch(() => setError('Could not load activity.'));
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
        return <p className="py-6 text-center text-sm text-gray-400">No activity yet.</p>;
    }

    return (
        <ol className="space-y-1 border-l border-gray-200 pl-4">
            {activities.map((activity) => (
                <li key={activity.id} className="relative py-1.5">
                    <span className="absolute -left-[21px] top-3 h-2.5 w-2.5 rounded-full border-2 border-white bg-indigo-500 shadow" />
                    <p className="text-sm text-gray-700">
                        <span className="font-medium text-gray-900">{activity.actor?.name ?? 'Someone'}</span>{' '}
                        {describe(activity.action, activity.data)}
                    </p>
                    <p className="text-xs text-gray-400">{new Date(activity.created_at).toLocaleString()}</p>
                </li>
            ))}
        </ol>
    );
}