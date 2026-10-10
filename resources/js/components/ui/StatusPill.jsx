/**
 * StatusPill — Figma "Status / Pill" component
 * (reusable-components/00 — Design System).
 *
 * Soft-tinted background with a solid same-hue label; optional leading dot.
 * Semantic variants reuse the exact Figma palette:
 *   success #E6F7EF/#1F9B69 · info #E9ECFF/#4B5EF5 · warning #FFF3D9/#D58A16
 *   danger #FDECEF/#D94E61 · purple #F0ECFF/#7858DE · teal #25A6A1 · neutral
 * A custom `color` prop still renders the `${color}22` fallback used by
 * dynamic category/tag pills.
 */
const VARIANTS = {
    success: { bg: 'var(--status-success-soft)', text: 'var(--status-success)' },
    approved: { bg: 'var(--status-success-soft)', text: 'var(--status-success)' },
    active: { bg: 'var(--status-success-soft)', text: 'var(--status-success)' },
    healthy: { bg: 'var(--status-success-soft)', text: 'var(--status-success)' },
    operational: { bg: 'var(--status-success-soft)', text: 'var(--status-success)' },
    paid: { bg: 'var(--status-success-soft)', text: 'var(--status-success)' },

    progress: { bg: 'var(--status-info-soft)', text: 'var(--status-info)' },
    info: { bg: 'var(--status-info-soft)', text: 'var(--status-info)' },
    enabled: { bg: 'var(--status-info-soft)', text: 'var(--status-info)' },

    warning: { bg: 'var(--status-warning-soft)', text: 'var(--status-warning)' },
    review: { bg: 'var(--status-warning-soft)', text: 'var(--status-warning)' },
    attention: { bg: 'var(--status-warning-soft)', text: 'var(--status-warning)' },
    pending: { bg: 'var(--status-warning-soft)', text: 'var(--status-warning)' },

    danger: { bg: 'var(--status-danger-soft)', text: 'var(--status-danger)' },
    blocked: { bg: 'var(--status-danger-soft)', text: 'var(--status-danger)' },
    urgent: { bg: 'var(--status-danger-soft)', text: 'var(--status-danger)' },
    overdue: { bg: 'var(--status-danger-soft)', text: 'var(--status-danger)' },

    purple: { bg: 'var(--status-purple-soft)', text: 'var(--status-purple)' },
    teal: { bg: '#E3F6F5', text: 'var(--status-teal)' },
    neutral: { bg: 'var(--track)', text: 'var(--text-secondary)' },
    draft: { bg: 'var(--track)', text: 'var(--text-secondary)' },
    closed: { bg: 'var(--track)', text: 'var(--text-secondary)' },
};

const SIZES = {
    md: 'h-[30px] rounded-[9px] px-3 text-[12px]',
    sm: 'h-6 rounded-[8px] px-2.5 text-[11px]',
};

export default function StatusPill({ color, variant, label, dot = false, size = 'md', className = '' }) {
    const key = String(variant || label || '').toLowerCase().trim().replace(/\s+/g, '_');
    const resolved = !color ? VARIANTS[key] : null;

    const styles = resolved
        ? { backgroundColor: resolved.bg, color: resolved.text }
        : { backgroundColor: color ? `${color}1f` : 'var(--track)', color: color || 'var(--text-secondary)' };

    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1.5 font-semibold leading-none tracking-[-0.01em] ${SIZES[size] || SIZES.md} ${className}`}
            style={styles}
        >
            {dot && (
                <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ backgroundColor: 'currentColor' }} />
            )}
            <span>{label}</span>
        </span>
    );
}
