export function formatDate(iso, fallback = '—') {
    if (!iso) return fallback;
    return new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatDateTime(iso, fallback = '—') {
    if (!iso) return fallback;
    return new Date(iso).toLocaleString();
}

export function formatPrice(plan = {}) {
    const cents = Number(plan.price_cents || 0);
    const value = cents === 0 ? '$0' : (cents / 100).toLocaleString('en-US', { style: 'currency', currency: plan.currency || 'USD' });
    return plan.billing_cycle === 'annual' ? `${value}/mo billed annually` : `${value}/mo`;
}