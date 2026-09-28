import { projectUrl, taskUrl, workspaceUrl } from './deepLinks';

export function describeNotification(type, data = {}, actorName = 'Someone') {
    const key = data.key || 'task';
    switch (type) {
        case 'task.assigned':
            return `${actorName} assigned ${key} to you`;
        case 'task.commented':
            return `${actorName} commented on ${key}`;
        case 'task.work_logged':
            return `${actorName} logged ${data.duration_minutes ?? ''}m on ${key}`;
        case 'task.status_changed':
            return `${actorName} moved ${key} to ${data.to_status}`;
        case 'task.unblocked':
            return `${actorName} unblocked ${key}`;
        case 'hrms.onboarding.task_due':
            return describeTaskDue(data);
        default:
            return 'You have a new notification';
    }
}

/**
 * An onboarding nudge names the item, the hire, and the urgency — not the
 * actor, because the sender is a command, not a person, and “Someone wants
 * you to …” would be a lie about who asked.
 */
function describeTaskDue(data = {}) {
    const what = data.title ? `“${data.title}”` : 'An onboarding item';
    const who = data.employee_name ? ` for ${data.employee_name}` : '';

    if (!data.due_date) return `${what}${who} needs attention`;

    const today = new Date().toISOString().slice(0, 10);

    if (data.due_date < today) return `${what}${who} is overdue since ${data.due_date}`;
    if (data.due_date === today) return `${what}${who} is due today`;

    return `${what}${who} is due ${data.due_date}`;
}

const SECTION_BY_TYPE = {
    'task.commented': 'comments',
    'task.work_logged': 'time',
};

/**
 * Builds a deep link for a notification. Task notifications land on the
 * project's Tasks tab with the drawer auto-opened on the relevant section
 * (comments for task.commented, time for task.work_logged, details otherwise).
 * Onboarding nudges land on the case detail itself — the list page takes no
 * case parameter, so linking there would drop the reader one click away from
 * the item they were nudged about.
 */
export function notificationHref(data = {}, type = '') {
    const { project_id, workspace_id, key, onboarding_case_id } = data || {};

    if (type === 'hrms.onboarding.task_due' && onboarding_case_id) {
        return `/hrms/onboarding/cases/${onboarding_case_id}`;
    }

    if (project_id && key) {
        return taskUrl(project_id, key, SECTION_BY_TYPE[type] ?? null);
    }

    if (project_id) return projectUrl(project_id);
    if (workspace_id) return workspaceUrl(workspace_id);
    return '/';
}

export function timeAgo(value) {
    if (!value) return '';
    const seconds = Math.floor((Date.now() - new Date(value).getTime()) / 1000);
    if (seconds < 45) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    return new Date(value).toLocaleDateString();
}