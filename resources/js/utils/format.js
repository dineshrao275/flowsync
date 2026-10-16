import i18n, { intlTag } from '../i18n';

// The active interface language drives every formatter; relative-time words
// come from `Intl.RelativeTimeFormat`, so no unit vocabulary lives in the dictionaries.
const tag = () => intlTag(i18n.language);

export function formatFullDateTime(iso, fallback = '—') {
    if (!iso) return fallback;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return fallback;

    return d.toLocaleString(tag(), {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
    });
}

export function formatRelativeTime(iso, { short = false, fallback = '—' } = {}) {
    if (!iso) return fallback;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return fallback;

    const diffSec = Math.floor((Date.now() - d.getTime()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(tag(), { numeric: 'auto', style: short ? 'narrow' : 'long' });

    // Recent (under 45s either way, or up to a minute ahead): one dictionary phrase.
    if (diffSec < 45 && diffSec >= -60) {
        return i18n.t('ui.justNow');
    }

    // Future timestamps (e.g. token expiration) and past ones share one ladder;
    // the unit words and word order come from `Intl`, not from this file.
    const future = diffSec < 0;
    const abs = Math.abs(diffSec);
    const sign = future ? 1 : -1;
    const mins = Math.floor(abs / 60);
    if (mins < 60) return rtf.format(sign * mins, 'minute');
    const hours = Math.floor(abs / 3600);
    if (hours < 24) return rtf.format(sign * hours, 'hour');
    const days = Math.floor(abs / 86400);
    if (days < (future ? 30 : 7)) return rtf.format(sign * days, 'day');

    const sameYear = d.getFullYear() === new Date().getFullYear();
    if (sameYear && !future) {
        return d.toLocaleDateString(tag(), { month: 'short', day: 'numeric' });
    }

    return d.toLocaleDateString(tag(), { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatDate(iso, fallback = '—') {
    if (!iso) return fallback;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return fallback;
    return d.toLocaleDateString(tag(), { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatDateTime(iso, fallback = '—') {
    if (!iso) return fallback;
    return formatRelativeTime(iso, { fallback });
}

export function formatPrice(plan = {}) {
    const cents = Number(plan.price_cents || 0);
    const value = cents === 0
        ? new Intl.NumberFormat(tag(), { style: 'currency', currency: plan.currency || 'USD', maximumFractionDigits: 0 }).format(0)
        : (cents / 100).toLocaleString(tag(), { style: 'currency', currency: plan.currency || 'USD' });
    return i18n.t(plan.billing_cycle === 'annual' ? 'ui.pricePerMonthAnnual' : 'ui.pricePerMonth', { value });
}

/** Date-only (`YYYY-MM-DD`) values render in the interface language, anchored at local midnight. */
export function formatDateOnly(value, fallback = '—') {
    if (!value) return fallback;
    const d = new Date(`${value}T00:00:00`);
    if (Number.isNaN(d.getTime())) return fallback;
    return d.toLocaleDateString(tag());
}

/** Clock time in the interface language. */
export function formatTime(iso, fallback = '—') {
    if (!iso) return fallback;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return fallback;
    return d.toLocaleTimeString(tag(), { hour: '2-digit', minute: '2-digit' });
}

/** Integers / counts grouped for the interface language. */
export function formatNumber(value) {
    return Number(value ?? 0).toLocaleString(tag());
}
