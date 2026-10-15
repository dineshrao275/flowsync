/**
 * Role vocabularies shared across the TMS surfaces. Workspace membership is
 * owner/admin/member; a project role is lead/developer/viewer. The API sends
 * slugs and the UI prints the label lowercased inside a Badge — so the map and
 * the lowercasing live here once instead of in three pages.
 */
export const WORKSPACE_ROLE_LABELS = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
};

export const PROJECT_ROLE_LABELS = {
    lead: 'Lead',
    developer: 'Developer',
    viewer: 'Viewer',
};

const lower = (value) => (typeof value === 'string' ? value.toLowerCase() : '');

export function workspaceRoleLabel(slug) {
    return lower(WORKSPACE_ROLE_LABELS[slug]) || slug || '';
}

export function projectRoleLabel(slug, fallbackName = '') {
    return lower(PROJECT_ROLE_LABELS[slug]) || lower(fallbackName) || slug || '';
}
