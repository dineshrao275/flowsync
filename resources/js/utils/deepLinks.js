export function taskUrl(projectId, key, section = null) {
    let url = `/projects/${projectId}?tab=tasks&task=${encodeURIComponent(key)}`;
    if (section) url += `&section=${section}`;
    return url;
}

export function projectUrl(projectId, tab = null) {
    if (!tab) return `/projects/${projectId}`;
    return `/projects/${projectId}?tab=${tab}`;
}

export function workspaceUrl(workspaceId, tab = null) {
    if (!tab) return `/workspaces/${workspaceId}`;
    return `/workspaces/${workspaceId}?tab=${tab}`;
}