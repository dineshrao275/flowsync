import i18n, { intlTag } from '../i18n';

/**
 * Locale-aware calendar chrome (i18n). Monday-first sequences anchored on
 * 2024-01-01 (a Monday), so every locale lands Mon…Sun in its own order.
 */
function sequence(length, style) {
    const tag = intlTag(i18n.language);

    return Array.from(
        { length },
        (_, i) => new Date(2024, 0, 1 + i).toLocaleDateString(tag, { weekday: style }),
    );
}

export function weekdayNames() {
    return sequence(7, 'short');
}

export function weekdayInitials() {
    return sequence(7, 'narrow');
}

export function monthNames() {
    const tag = intlTag(i18n.language);

    return Array.from(
        { length: 12 },
        (_, i) => new Date(2024, i, 1).toLocaleDateString(tag, { month: 'long' }),
    );
}
