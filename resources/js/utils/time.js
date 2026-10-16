import i18n from '../i18n';

export function formatMinutes(minutes) {
    if (minutes == null) return '—';
    const m = Math.max(0, Math.round(minutes));
    const h = Math.floor(m / 60);
    const r = m % 60;
    if (h === 0) return i18n.t('units.minutes', { count: r });
    if (r === 0) return i18n.t('units.hours', { count: h });
    return i18n.t('units.hoursMinutes', { hours: h, minutes: r });
}