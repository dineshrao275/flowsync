import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { taskUrl } from '../../utils/deepLinks';
import Spinner from '../ui/Spinner';
import EmptyState from '../ui/EmptyState';

/**
 * The profile's Tasks tab: this person's linked work with status pills
 * and links back into the project. HR-owned asks (`hrms_employee_id`)
 * surface here too whenever a link names them — the tab reads bridges,
 * not assignments, so it never duplicates the project's own views.
 */
export default function EmployeeTasks({ employeeId }) {
    const [tasks, setTasks] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!employeeId) return;

        api.get(`/hrms/employees/${employeeId}/tasks`)
            .then(({ data }) => setTasks(data.tasks ?? []))
            .catch(() => setError('Unable to load linked tasks.'));
    }, [employeeId]);

    if (error) return <p className="py-8 text-center text-sm text-red-600">{error}</p>;
    if (!tasks) return <Spinner />;

    if (tasks.length === 0) {
        return <EmptyState title="No linked tasks" message="No project task counts toward this person yet." />;
    }

    return (
        <ul className="divide-y divide-gray-100">
            {tasks.map((task) => (
                <li key={`${task.id}-${task.link?.id ?? 'none'}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                    <Link
                        to={taskUrl(task.project?.id, task.key)}
                        className="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        {task.key} · {task.title}
                    </Link>
                    {task.status && (
                        <span
                            className="rounded-full px-2 py-0.5 text-xs font-medium"
                            style={{ backgroundColor: `${task.status.color ?? '#6b7280'}22`, color: task.status.color ?? '#6b7280' }}
                        >
                            {task.status.name}
                        </span>
                    )}
                    {task.link && (
                        <span className="rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700">
                            {task.link.kind_label ?? task.link.kind}
                        </span>
                    )}
                    <span className="ml-auto text-xs text-gray-400">{task.project?.name}</span>
                </li>
            ))}
        </ul>
    );
}
