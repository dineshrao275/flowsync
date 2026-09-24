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
        default:
            return 'You have a new notification';
    }
}

const SECTION_BY_TYPE = {
    'task.commented': 'comments',
    'task.work_logged': 'time',
};

/**
 * Builds a deep link for a notification. Task notifications land on the
 * project's Tasks tab with the drawer auto-opened on the relevant section
 * (comments for task.commented, time for task.work_logged, details otherwise).
 */
export function notificationHref(data = {}, type = '') {
    const { project_id, workspace_id, key } = data || {};

    if (project_id && key) {
        let query = `tab=tasks&task=${encodeURIComponent(key)}`;
        const section = SECTION_BY_TYPE[type];
        if (section) query += `&section=${section}`;
        return `/projects/${project_id}?${query}`;
    }

    if (project_id) return `/projects/${project_id}`;
    if (workspace_id) return `/workspaces/${workspace_id}`;
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