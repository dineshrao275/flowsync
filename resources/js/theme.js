const STORAGE_PREFIX = (typeof window !== 'undefined' && window.__FLOWSYNC_CONFIG__?.storagePrefix) || 'flowsync';

export const THEME_STORAGE_KEY = `${STORAGE_PREFIX}.theme`;

export const THEME_MODES = ['light', 'dark', 'system'];

export const DEFAULT_THEME = {
    sidebar_bg: '#0f172a',
    sidebar_hover: '#1e293b',
    active_menu: '#2563eb',
    sidebar_text: '#cbd5e1',
    dashboard_bg: '#f1f5f9',
    header_bg: '#ffffff',
    header_text: '#0f172a',
    card_bg: '#ffffff',
    accent: '#2563eb',
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

const THEME_VARS_KEY = `${STORAGE_PREFIX}.theme.vars`;
const THEME_SURFACE_DARK_KEY = `${STORAGE_PREFIX}.theme.surfaceDark`;

function normHex(hex) {
    return rgbToHex(hexToRgb(hex));
}

function hexToRgb(hex) {
    const value = String(hex || '').replace('#', '').trim();
    const full = value.length === 3 ? value.split('').map((c) => c + c).join('') : value;

    if (!/^[0-9a-fA-F]{6}$/.test(full)) return { r: 37, g: 99, b: 235 };

    return {
        r: parseInt(full.slice(0, 2), 16),
        g: parseInt(full.slice(2, 4), 16),
        b: parseInt(full.slice(4, 6), 16),
    };
}

function rgbToHex({ r, g, b }) {
    const clamp = (n) => Math.max(0, Math.min(255, Math.round(n)));

    return `#${[r, g, b].map((n) => clamp(n).toString(16).padStart(2, '0')).join('')}`;
}

function mix(from, to, amount) {
    const a = hexToRgb(from);
    const b = hexToRgb(to);

    return rgbToHex({
        r: a.r + (b.r - a.r) * amount,
        g: a.g + (b.g - a.g) * amount,
        b: a.b + (b.b - a.b) * amount,
    });
}

function luminance(hex) {
    const { r, g, b } = hexToRgb(hex);
    const channel = (v) => {
        const s = v / 255;

        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

/** WCAG 2.x contrast ratio between two hex colours (1–21). */
export function contrastRatio(a, b) {
    const la = luminance(a);
    const lb = luminance(b);

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

const isHex = (value) => /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(String(value || '').trim());

/** The better of two label colours on a fill (white vs near-black), by real contrast, not a luminance guess. */
export function bestOn(bg, light = '#ffffff', dark = '#0f172a') {
    const candidates = [light, dark, '#000000'];

    return candidates.reduce((best, color) => (contrastRatio(color, bg) > contrastRatio(best, bg) ? color : best));
}

/** Best label ratio any of white / near-black / black reaches on this fill. */
function labelRatio(bg) {
    return contrastRatio(bestOn(bg), bg);
}

/** Luminance at which black and white labels are equally readable (~0.179). */
const MIDPOINT = 0.179;

/**
 * Moves `fg` the least amount toward black or white (keeping its hue) until it reaches `min`:1 on EVERY
 * background given. A colour that already passes is returned untouched, so a healthy theme looks exactly as
 * it was picked; only a failing one is nudged.
 */
export function ensureContrast(fg, backgrounds, min = 4.5, toward = null) {
    const list = Array.isArray(backgrounds) ? backgrounds : [backgrounds];
    const start = normHex(isHex(fg) ? fg : '#808080');
    const passes = (color) => list.every((bg) => contrastRatio(color, bg) >= min);

    if (passes(start)) return start;

    const average = list.reduce((sum, bg) => sum + luminance(bg), 0) / list.length;
    const preferred = toward ?? (average > MIDPOINT ? 'dark' : 'light');
    const targets = preferred === 'dark' ? ['#000000', '#ffffff'] : ['#ffffff', '#000000'];

    for (const target of targets) {
        for (let amount = 0.02; amount < 1.0001; amount += 0.02) {
            const candidate = mix(start, target, Math.min(1, amount));

            if (passes(candidate)) return candidate;
        }
    }

    return targets[0];
}

/** Pushes a background toward `target` until `ok(luminance)` holds (used to keep a canvas on its card's side). */
function pushSurface(color, target, ok) {
    let current = normHex(color);

    for (let amount = 0.04; !ok(luminance(current)) && amount <= 1.0001; amount += 0.04) {
        current = mix(color, target, Math.min(1, amount));
    }

    return current;
}

/**
 * Pairs an admin can break by picking colours freely. Text pairs need 4.5:1
 * (WCAG AA body text); the accent against the card is a UI component
 * boundary, so 3:1. `resolveTheme()` repairs every one of them automatically
 * when the theme is applied; this list is what the drawer reports as
 * "adjusted" so the admin knows why a swatch looks different on screen.
 */
export const CONTRAST_PAIRS = [
    { id: 'sidebar_text', fg: 'sidebar_text', bg: 'sidebar_bg', min: 4.5 },
    { id: 'active_menu', fg: 'active_menu', bg: 'sidebar_bg', min: 3 },
    { id: 'header_text', fg: 'header_text', bg: 'header_bg', min: 4.5 },
    { id: 'accent', fg: 'accent', bg: 'card_bg', min: 3 },
];

export function themeContrastIssues(theme) {
    return CONTRAST_PAIRS.flatMap((pair) => {
        const fg = theme?.[pair.fg];
        const bg = theme?.[pair.bg];

        if (!isHex(fg) || !isHex(bg)) return [];

        const ratio = contrastRatio(fg, bg);

        return ratio < pair.min ? [{ ...pair, ratio }] : [];
    });
}

/**
 * Structural surfaces used when the dark scheme is active. The admin's own
 * `accent` / `active_menu` colors are kept (hue-preserving, lightened only as
 * far as needed) so branding survives the switch.
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

/**
 * Data-viz palette (lines, bars, status dots and the text that sits beside them). Each entry is moved only as far
 * as needed to be >= 4.5:1 on every surface and on its own 22% tint, so it is safe as a stroke AND as text.
 */
const CHART = {
    light: { blue: '#2563eb', green: '#047857', amber: '#b45309', red: '#b91c1c', violet: '#6d28d9', sky: '#0369a1', orange: '#c2410c', neutral: '#4b5563' },
    dark: { blue: '#60a5fa', green: '#34d399', amber: '#fbbf24', red: '#f87171', violet: '#a78bfa', sky: '#38bdf8', orange: '#fb923c', neutral: '#9ca3af' },
};

/** Neutral roles per scheme. The values are the design defaults; resolveTheme() only moves one if it would fail. */
const SCHEME = {
    light: {
        fg: '#0f172a', fgMuted: '#475569', fgSubtle: '#64748b', lineInput: '#8392a5',
        success: '#047857', successSoft: '#ecfdf5', warning: '#b45309', warningSoft: '#fffbeb',
        danger: '#b91c1c', dangerSoft: '#fef2f2', info: '#0369a1', infoSoft: '#f0f9ff',
        gray50: '#f9fafb', gray100: '#f3f4f6',
        gray: { 400: '#6b7280', 500: '#6b7280', 600: '#4b5563', 700: '#374151', 800: '#1f2937', 900: '#111827', 950: '#030712' },
        slate: { 400: '#64748b', 500: '#64748b', 600: '#475569', 700: '#334155', 800: '#1e293b', 900: '#0f172a', 950: '#020617' },
    },
    dark: {
        fg: '#e8f0ff', fgMuted: '#b8c5e6', fgSubtle: '#93a4d6', lineInput: '#6b7689',
        success: '#6ee7b7', successSoft: '#11332b', warning: '#fcd34d', warningSoft: '#362d22',
        danger: '#f87171', dangerSoft: '#450a0a', info: '#7dd3fc', infoSoft: '#112f45',
        gray50: '#171d2b', gray100: '#1e2534',
        gray: { 400: '#93a4d6', 500: '#b8c5e6', 600: '#cdd8ff', 700: '#e1e8ff', 800: '#e8eaef', 900: '#f2f4f8', 950: '#ffffff' },
        slate: { 400: '#8a93a5', 500: '#a7afbe', 600: '#c2c9d5', 700: '#d8dce3', 800: '#e8eaef', 900: '#f2f4f8', 950: '#ffffff' },
    },
};

/**
 * The one place colours are decided. Input: whatever the admin picked (any hex, any mode). Output: every CSS
 * custom property the UI reads, derived so that text is readable on the surface it actually sits on:
 *
 *  - scheme follows the CARD surface (a dark card in "light" mode gets the dark neutral scale), and the page
 *    canvas is kept on the card's side of the scale so headings on it stay legible;
 *  - sidebar / header text, the active-menu fill, the accent, the neutral text roles, the grey/slate text
 *    ramp and the status colours are each nudged (hue kept) only as far as WCAG AA requires, against every
 *    background they can appear on;
 *  - hover/active states of filled controls are computed from the fill so their label stays AA;
 *  - `adjustments` lists the picks that had to change, for the theme drawer.
 *
 * @returns {{dark: boolean, surfaceDark: boolean, vars: Record<string,string>, adjustments: Array<{key:string, from:string, to:string}>}}
 */
export function resolveTheme(input) {
    const picked = { ...DEFAULT_THEME, ...(input || {}) };
    THEME_FIELDS.forEach(({ key }) => {
        if (!isHex(picked[key])) picked[key] = DEFAULT_THEME[key];
    });

    const modeDark = resolveMode(picked.mode) === 'dark';
    const adjustments = [];
    const note = (key, to) => {
        const value = normHex(to);

        if (value !== normHex(picked[key])) adjustments.push({ key, from: normHex(picked[key]), to: value });

        return value;
    };

    // --- surfaces -----------------------------------------------------------------------------------------
    const surfaces = modeDark ? { ...DARK_SURFACES } : {
        sidebar_bg: picked.sidebar_bg, sidebar_hover: picked.sidebar_hover, sidebar_text: picked.sidebar_text,
        dashboard_bg: picked.dashboard_bg, header_bg: picked.header_bg, header_text: picked.header_text, card_bg: picked.card_bg,
    };
    const card = normHex(surfaces.card_bg);
    const surfaceDark = !modeDark && luminance(card) < MIDPOINT;
    const dark = modeDark || surfaceDark;
    const scheme = dark ? SCHEME.dark : SCHEME.light;

    let canvas = normHex(surfaces.dashboard_bg);
    if (!modeDark) {
        canvas = dark
            ? pushSurface(canvas, '#000000', (l) => l <= 0.12)
            : pushSurface(canvas, '#ffffff', (l) => l >= 0.3);
        canvas = note('dashboard_bg', canvas);
    }
    const sunken = mix(card, canvas, 0.5);
    const onSurface = [card, canvas, sunken];
    // Text on this scheme's surfaces always moves toward the scheme's text end (light text on dark, dark on light).
    const toward = dark ? 'light' : 'dark';

    // --- neutral text roles, borders, status colours -------------------------------------------------------
    const vars = {
        '--card-bg': card,
        '--dashboard-bg': canvas,
        '--surface-sunken': sunken,
        '--fg': ensureContrast(scheme.fg, onSurface, 4.6, toward),
        '--fg-muted': ensureContrast(scheme.fgMuted, onSurface, 4.6, toward),
        '--fg-subtle': ensureContrast(scheme.fgSubtle, onSurface, 4.6, toward),
        '--line-input': ensureContrast(scheme.lineInput, [card], 3, toward),
    };
    [['success', 'successSoft'], ['warning', 'warningSoft'], ['danger-fg', 'dangerSoft', 'danger'], ['info', 'infoSoft']].forEach(([name, soft, base]) => {
        vars[`--${name}`] = ensureContrast(scheme[base || name], [...onSurface, scheme[soft]], 4.6, toward);
    });

    Object.entries(CHART[dark ? 'dark' : 'light']).forEach(([name, base]) => {
        vars[`--chart-${name}`] = ensureContrast(base, [...onSurface, mix(card, base, 0.22)], 4.5, toward);
    });

    // --- grey / slate text ramp (hundreds of components use text-gray-N) -------------------------------------
    const rampBackgrounds = [...onSurface, scheme.gray50, scheme.gray100];
    ['gray', 'slate'].forEach((family) => {
        Object.entries(scheme[family]).forEach(([step, base]) => {
            vars[`--color-${family}-${step}`] = ensureContrast(base, rampBackgrounds, 4.6, toward);
        });
    });

    // --- sidebar ----------------------------------------------------------------------------------------------
    const sidebarBg = normHex(surfaces.sidebar_bg);
    let sidebarHover = normHex(surfaces.sidebar_hover);
    const sidebarText = modeDark
        ? normHex(surfaces.sidebar_text)
        : note('sidebar_text', ensureContrast(
            surfaces.sidebar_text,
            // Only count the hover fill when it sits on the same side of the scale; otherwise it is repaired below.
            (luminance(sidebarBg) > MIDPOINT) === (luminance(sidebarHover) > MIDPOINT) ? [sidebarBg, sidebarHover] : [sidebarBg],
            4.5,
        ));
    if (!modeDark) {
        // The hover fill must stay a shade of the sidebar, close enough that the same label is readable on both.
        for (let amount = 0.2; contrastRatio(sidebarText, sidebarHover) < 4.5 && amount <= 1.0001; amount += 0.2) {
            sidebarHover = mix(sidebarHover, sidebarBg, Math.min(1, amount));
        }
        sidebarHover = note('sidebar_hover', sidebarHover);
    }
    // The active item is a filled pill: readable against the sidebar (3:1) AND carrying a label that is AA on it.
    let activeMenu = ensureContrast(picked.active_menu, [sidebarBg], 3);
    const away = luminance(activeMenu) >= luminance(sidebarBg) ? '#ffffff' : '#000000';
    for (let amount = 0.04; labelRatio(activeMenu) < 4.5 && amount <= 1.0001; amount += 0.04) {
        activeMenu = mix(activeMenu, away, 0.04);
    }
    activeMenu = note('active_menu', activeMenu);
    vars['--sidebar-bg'] = sidebarBg;
    vars['--sidebar-hover'] = sidebarHover;
    vars['--sidebar-text'] = sidebarText;
    // Section labels and captions used `opacity` on the text colour, which silently halves its contrast.
    vars['--sidebar-text-muted'] = ensureContrast(mix(sidebarText, sidebarBg, 0.3), [sidebarBg, sidebarHover], 4.5);
    vars['--sidebar-title'] = bestOn(sidebarBg);
    vars['--active-menu'] = activeMenu;
    vars['--active-menu-contrast'] = bestOn(activeMenu);

    // --- header ---------------------------------------------------------------------------------------------------
    vars['--header-bg'] = normHex(surfaces.header_bg);
    vars['--header-text'] = modeDark ? normHex(surfaces.header_text) : note('header_text', ensureContrast(surfaces.header_text, [vars['--header-bg']], 4.5));

    // --- accent (used for text, borders and fills alike, so it is text-safe on every surface) -----------------------
    const accent = note('accent', ensureContrast(picked.accent, onSurface, 4.5, toward));
    const accentText = bestOn(accent);
    const accentLabelIsLight = accentText === '#ffffff';
    const fill = (amount) => (accentLabelIsLight ? mix(accent, '#000000', amount) : mix(accent, '#ffffff', amount));
    // The tint behind chips/selected rows shrinks until the accent can still be read on it.
    let strength = dark ? 0.22 : 0.1;
    let soft = mix(card, accent, strength);
    let softHover = mix(card, accent, strength * 1.5);
    while (strength > 0.01 && ![soft, softHover].every((bg) => contrastRatio(ensureContrast(accent, [bg, ...onSurface], 4.6, toward), bg) >= 4.5)) {
        strength *= 0.6;
        soft = mix(card, accent, strength);
        softHover = mix(card, accent, strength * 1.5);
    }
    vars['--accent'] = accent;
    vars['--accent-hover'] = fill(0.12);
    vars['--accent-active'] = fill(0.22);
    vars['--accent-contrast'] = accentText;
    vars['--accent-soft'] = soft;
    vars['--accent-soft-hover'] = softHover;
    vars['--accent-soft-text'] = ensureContrast(accent, [soft, softHover, ...onSurface], 4.6, toward);
    vars['--accent-ring'] = ensureContrast(mix(card, accent, dark ? 0.55 : 0.4), [card, canvas], 3, toward);

    // --- destructive controls -------------------------------------------------------------------------------------
    const danger = '#dc2626';
    vars['--danger'] = danger;
    vars['--danger-hover'] = mix(danger, '#000000', 0.12);
    vars['--danger-active'] = mix(danger, '#000000', 0.24);
    vars['--danger-contrast'] = '#ffffff';
    vars['--danger-ring'] = ensureContrast('#f87171', [card, canvas], 3, toward);

    return { dark, surfaceDark, vars, adjustments };
}

/** Kept for callers that only need the accent family or the destructive family. */
export function accentTokens(accent, dark = false) {
    const { vars } = resolveTheme({ accent, mode: dark ? 'dark' : 'light' });

    return Object.fromEntries(Object.entries(vars).filter(([name]) => name.startsWith('--accent')));
}

export function dangerTokens(dark = false) {
    const { vars } = resolveTheme({ mode: dark ? 'dark' : 'light' });

    return Object.fromEntries(Object.entries(vars).filter(([name]) => name.startsWith('--danger') && name !== '--danger-fg'));
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
    return resolveTheme(theme).dark;
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
 * Applies a theme: resolves every colour (see resolveTheme), toggles the `dark` class (which selects the
 * dark neutral scale in app.css), sets `color-scheme` for native controls and writes the custom properties.
 * Returns the merged picks, as before.
 */
export function applyTheme(theme) {
    if (typeof document === 'undefined') return null;

    const merged = { ...DEFAULT_THEME, ...(theme || {}) };
    const { dark, surfaceDark, vars } = resolveTheme(merged);
    const root = document.documentElement;

    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';
    Object.entries(vars).forEach(([name, value]) => root.style.setProperty(name, value));

    try {
        window.localStorage.setItem(THEME_STORAGE_KEY, JSON.stringify(merged));
        // Cached so the pre-hydration boot script can paint the resolved palette without redoing the color math.
        window.localStorage.setItem(THEME_VARS_KEY, JSON.stringify(vars));
        window.localStorage.setItem(THEME_SURFACE_DARK_KEY, surfaceDark ? '1' : '0');
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

    const root = document.documentElement;
    let surfaceDark = false;

    try {
        surfaceDark = window.localStorage.getItem(THEME_SURFACE_DARK_KEY) === '1';
    } catch {
        // fall through to the mode alone
    }

    const dark = resolveMode(stored.mode) === 'dark' || surfaceDark;

    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';

    try {
        const cached = JSON.parse(window.localStorage.getItem(THEME_VARS_KEY) || 'null');

        if (cached) {
            Object.entries(cached).forEach(([name, value]) => root.style.setProperty(name, value));
        }
    } catch {
        // React applies the real values on mount.
    }
}
