/**
 * Shared Recharts theme (dashboard + reports shells). Series fills read the
 * admin's `--accent` token back from CSS instead of hardcoding a brand hex,
 * and ticks/grids/tooltips follow the resolved color scheme — one definition
 * for every analytics surface, light and dark.
 */
export function accentHex() {
    if (typeof document === 'undefined') return '#2563eb';

    return getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#2563eb';
}

export function chartPalette(mode) {
    const dark = mode === 'dark';

    return {
        dark,
        accent: accentHex(),
        secondary: '#94a3b8',
        tick: dark ? '#a7afbe' : '#64748b',
        grid: dark ? '#28303f' : '#e2e8f0',
        tooltip: {
            backgroundColor: 'var(--card-bg)',
            border: `1px solid ${dark ? '#28303f' : '#e5e7eb'}`,
            borderRadius: '8px',
            fontSize: '12px',
            color: dark ? '#e8eaef' : '#1f2937',
            boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
        },
    };
}

export function chartAxisProps(mode) {
    return { tick: { fontSize: 11, fill: chartPalette(mode).tick }, tickMargin: 8 };
}

export function chartTooltipProps(mode) {
    const palette = chartPalette(mode);

    return {
        contentStyle: palette.tooltip,
        cursor: { fill: palette.dark ? 'rgba(167,175,190,0.12)' : 'rgba(37,99,235,0.06)' },
    };
}
