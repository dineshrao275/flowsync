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

const CSS_VARS = Object.keys(DEFAULT_THEME);

function cssVar(key) {
    return `--${key.replace(/_/g, '-')}`;
}

export function applyTheme(theme) {
    const merged = { ...DEFAULT_THEME, ...(theme || {}) };
    const root = document.documentElement;

    CSS_VARS.forEach((key) => {
        root.style.setProperty(cssVar(key), merged[key]);
    });
}
