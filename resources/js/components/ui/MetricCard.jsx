import StatusPill from './StatusPill';

/**
 * MetricCard — Figma metric tile (≈260×105): micro-label, large bold value,
 * optional semantic badge and an accent progress stripe over the `#EEF1F7`
 * track.
 */
export default function MetricCard({
    title,
    value,
    badge,
    badgeVariant = 'success',
    badgeColor,
    accentColor = 'var(--accent)',
    progress = 60,
    subtitle,
    className = '',
    onClick,
}) {
    return (
        <div
            onClick={onClick}
            className={`flex flex-col justify-between rounded-[12px] border border-[var(--border-hairline)] bg-[var(--card-bg)] p-4 shadow-[var(--shadow-card)] transition-all duration-150 ${
                onClick ? 'cursor-pointer hover:border-[var(--accent)]/40 hover:shadow-[var(--shadow-popover)]' : ''
            } ${className}`}
        >
            <div className="flex items-start justify-between gap-3">
                <span className="text-[11px] font-medium text-muted">{title}</span>
                {badge && <StatusPill label={badge} variant={badgeVariant} color={badgeColor} />}
            </div>

            <div className="mt-2 flex items-baseline gap-2">
                <span className="text-[28px] font-bold leading-none tracking-[-0.02em] text-ink">{value}</span>
                {subtitle && <span className="text-[11px] text-muted">{subtitle}</span>}
            </div>

            <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-[var(--track)]">
                <div
                    className="h-full rounded-full transition-all duration-300"
                    style={{ backgroundColor: accentColor, width: `${Math.min(100, Math.max(6, progress))}%` }}
                />
            </div>
        </div>
    );
}
