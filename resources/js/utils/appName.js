/**
 * The product name, edited by the Super Admin in Settings and injected by `app.blade.php`
 * (`window.__FLOWSYNC_CONFIG__.appName`). Never hardcode it: use this, or `{{app}}` inside locale strings
 * (registered as an i18next default variable).
 */
export function getAppName() {
    return (typeof window !== 'undefined' && window.__FLOWSYNC_CONFIG__?.appName) || 'FlowSync';
}

export function setAppName(name) {
    if (typeof window === 'undefined' || !name) return;
    window.__FLOWSYNC_CONFIG__ = { ...(window.__FLOWSYNC_CONFIG__ || {}), appName: name };
}
