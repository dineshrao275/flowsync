export function formatFullDateTime(iso, fallback = '—') {
    if (!iso) return fallback;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return fallback;

    return d.toLocaleString('en-US', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    });
}

export function formatRelativeTime(iso, { short = false, fallback = '—' } = {}) {
    if (!iso) return fallback;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return fallback;

    const diffMs = Date.now() - d.getTime();
    const diffSec = Math.floor(diffMs / 1000);

    // Future timestamps (e.g. token expiration)
    if (diffSec < -60) {
        const absSec = Math.abs(diffSec);
        const mins = Math.floor(absSec / 60);
        if (mins < 60) return `in ${mins}${short ? 'm' : mins === 1 ? ' minute' : ' minutes'}`;
        const hours = Math.floor(mins / 60);
        if (hours < 24) return `in ${hours}${short ? 'h' : hours === 1 ? ' hour' : ' hours'}`;
        const days = Math.floor(hours / 24);
        if (days < 30) return `in ${days}${short ? 'd' : days === 1 ? ' day' : ' days'}`;
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    // Past or very recent
    if (diffSec < 45 && diffSec >= -60) {
        return 'just now';
    }

    const mins = Math.floor(diffSec / 60);
    if (mins < 60) {
        return short ? `${mins}m ago` : `${mins} minute${mins === 1 ? '' : 's'} ago`;
    }

    const hours = Math.floor(diffSec / 3600);
    if (hours < 24) {
        return short ? `${hours}h ago` : `${hours} hour${hours === 1 ? '' : 's'} ago`;
    }

    const days = Math.floor(diffSec / 86400);
    if (days === 1 && !short) {
        return 'yesterday';
    }
    if (days < 7) {
        return short ? `${days}d ago` : `${days} days ago`;
    }

    // Within same calendar year
    const isSameYear = d.getFullYear() === new Date().getFullYear();
    if (isSameYear) {
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatDate(iso, fallback = '—') {
    if (!iso) return fallback;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return fallback;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatDateTime(iso, fallback = '—') {
    if (!iso) return fallback;
    return formatRelativeTime(iso, { fallback });
}

export function formatPrice(plan = {}) {
    const cents = Number(plan.price_cents || 0);
    const value = cents === 0 ? '$0' : (cents / 100).toLocaleString('en-US', { style: 'currency', currency: plan.currency || 'USD' });
    return plan.billing_cycle === 'annual' ? `${value}/mo billed annually` : `${value}/mo`;
}