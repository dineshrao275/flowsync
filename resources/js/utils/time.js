export function formatMinutes(minutes) {
    if (minutes == null) return '—';
    const m = Math.max(0, Math.round(minutes));
    const h = Math.floor(m / 60);
    const r = m % 60;
    if (h === 0) return `${r}m`;
    if (r === 0) return `${h}h`;
    return `${h}h ${r}m`;
}