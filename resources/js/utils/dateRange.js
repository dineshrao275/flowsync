function iso(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function startOfWeek(date) {
    const d = new Date(date);
    const day = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - day);
    d.setHours(0, 0, 0, 0);
    return d;
}

/**
 * Dashboard/report window presets. Each resolves to an inclusive
 * { from, to } pair (YYYY-MM-DD) the APIs already speak — the pages send
 * them as query params and re-render whatever comes back, so charts stay
 * correct for any span without client-side bucketing.
 */
export const RANGE_PRESETS = [
    { key: 'today', label: 'Today' },
    { key: 'week', label: 'This week' },
    { key: 'month', label: 'This month' },
    { key: 'last_month', label: 'Last month' },
    { key: 'months_3', label: 'Last 3 months' },
    { key: 'months_6', label: 'Last 6 months' },
    { key: 'year', label: 'This year' },
    { key: 'last_year', label: 'Last year' },
    { key: 'custom', label: 'Custom' },
];

export function resolveRange(key, custom = {}) {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    switch (key) {
        case 'today':
            return { from: iso(today), to: iso(today) };
        case 'week':
            return { from: iso(startOfWeek(now)), to: iso(today) };
        case 'month':
            return { from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: iso(today) };
        case 'last_month': {
            const first = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const last = new Date(today.getFullYear(), today.getMonth(), 0);
            return { from: iso(first), to: iso(last) };
        }
        case 'months_3': {
            const from = new Date(today);
            from.setMonth(from.getMonth() - 3);
            from.setDate(from.getDate() + 1);
            return { from: iso(from), to: iso(today) };
        }
        case 'months_6': {
            const from = new Date(today);
            from.setMonth(from.getMonth() - 6);
            from.setDate(from.getDate() + 1);
            return { from: iso(from), to: iso(today) };
        }
        case 'year':
            return { from: iso(new Date(today.getFullYear(), 0, 1)), to: iso(today) };
        case 'last_year':
            return { from: `${today.getFullYear() - 1}-01-01`, to: `${today.getFullYear() - 1}-12-31` };
        case 'custom':
            return { from: custom.from || iso(today), to: custom.to || iso(today) };
        default:
            return { from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: iso(today) };
    }
}

export function rangeLabel(key, range) {
    if (key === 'custom' || !key) return `${range.from} → ${range.to}`;
    return RANGE_PRESETS.find((p) => p.key === key)?.label ?? '';
}
