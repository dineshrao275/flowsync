export const THEME_STORAGE_KEY = 'flowsync.theme';

export const THEME_MODES = ['light', 'dark', 'system'];

export const DEFAULT_THEME = {
    sidebar_bg: '#0f172a',
    sidebar_hover: '#1e293b',
    active_menu: '#6366f1',
    sidebar_text: '#cbd5e1',
    dashboard_bg: '#f1f5f9',
    header_bg: '#ffffff',
    header_text: '#0f172a',
    card_bg: '#ffffff',
    accent: '#6366f1',
    mode: 'system',
};

export const THEME_FIELDS = [
    { key: 'sidebar_bg', label: 'Sidebar background' },
    { key: 'sidebar_hover', label: 'Sidebar hover color' },
    { key: 'active_menu', label: 'Active menu color' },
    { key: 'sidebar_text', label: 'Sidebar text color' },
    { key: 'dashboard_bg', label: 'Dashboard background' },
    { key: 'header_bg', label: 'Header background' },
    { key: 'header_text', label: 'Header text color' },
    { key: 'card_bg', label: 'Card background' },
    { key: 'accent', label: 'Accent color' },
];

export const THEME_PRESETS = [
    {
        name: 'Indigo Slate',
        theme: { ...DEFAULT_THEME },
    },
    {
        name: 'Emerald Dark',
        theme: {
            ...DEFAULT_THEME,
            sidebar_bg: '#022c22',
            sidebar_hover: '#064e3b',
            active_menu: '#10b981',
            sidebar_text: '#a7f3d0',
            dashboard_bg: '#ecfdf5',
            header_bg: '#ffffff',
            header_text: '#022c22',
            card_bg: '#ffffff',
            accent: '#10b981',
        },
    },
    {
        name: 'Rose Night',
        theme: {
            ...DEFAULT_THEME,
            sidebar_bg: '#1c1917',
            sidebar_hover: '#292524',
            active_menu: '#f43f5e',
            sidebar_text: '#fda4af',
            dashboard_bg: '#fff1f2',
            header_bg: '#ffffff',
            header_text: '#1c1917',
            card_bg: '#ffffff',
            accent: '#f43f5e',
        },
    },
    {
        name: 'Blue Steel',
        theme: {
            ...DEFAULT_THEME,
            sidebar_bg: '#0c4a6e',
            sidebar_hover: '#075985',
            active_menu: '#38bdf8',
            sidebar_text: '#bae6fd',
            dashboard_bg: '#f0f9ff',
            header_bg: '#ffffff',
            header_text: '#0c4a6e',
            card_bg: '#ffffff',
            accent: '#0284c7',
        },
    },
];

/**
 * Structural surfaces used when the dark scheme is active. The admin's own
 * `accent` / `active_menu` colors are kept so branding survives the switch.
 */
export const DARK_SURFACES = {
    sidebar_bg: '#0b0f1a',
    sidebar_hover: '#1b2333',
    sidebar_text: '#cbd5e1',
    dashboard_bg: '#0f1420',
    header_bg: '#151a26',
    header_text: '#e8eaf0',
    card_bg: '#171d2b',
};

const CSS_VARS = THEME_FIELDS.map((field) => field.key);

function cssVar(key) {
    return `--${key.replace(/_/g, '-')}`;
}

export function prefersDark() {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function resolveMode(mode) {
    const resolved = THEME_MODES.includes(mode) ? mode : 'system';

    if (resolved === 'system') {
        return prefersDark() ? 'dark' : 'light';
    }

    return resolved;
}

export function isDarkMode(theme) {
    return resolveMode(theme?.mode) === 'dark';
}

function readStoredTheme() {
    if (typeof window === 'undefined') return null;

    try {
        const raw = window.localStorage.getItem(THEME_STORAGE_KEY);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

/**
 * Applies a theme: toggles the `dark` class (which re-points the Tailwind gray
 * scale + `bg-white` surfaces via app.css), sets `color-scheme` for native
 * form controls, and writes the custom properties.
 */
export function applyTheme(theme) {
    if (typeof document === 'undefined') return null;

    const merged = { ...DEFAULT_THEME, ...(theme || {}) };
    const dark = resolveMode(merged.mode) === 'dark';
    const root = document.documentElement;

    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';

    CSS_VARS.forEach((key) => {
        root.style.setProperty(cssVar(key), dark && key in DARK_SURFACES ? DARK_SURFACES[key] : merged[key]);
    });

    try {
        window.localStorage.setItem(THEME_STORAGE_KEY, JSON.stringify(merged));
    } catch {
        // Private mode / storage disabled — the theme still applies for this page.
    }

    return merged;
}

/**
 * Re-applies the current theme when the OS scheme flips while in `system`
 * mode. Returns an unsubscribe function.
 */
export function watchSystemScheme(callback) {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return () => {};
    }

    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const handler = () => callback();

    query.addEventListener('change', handler);

    return () => query.removeEventListener('change', handler);
}

/**
 * Runs before React mounts (inline script in app.blade.php) so the stored
 * scheme is painted on the first frame instead of flashing light.
 */
export function bootStoredTheme() {
    const stored = readStoredTheme();

    if (!stored) return;

    const dark = resolveMode(stored.mode) === 'dark';
    const root = document.documentElement;

    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';
}
