/**
 * Button — Figma "Buttons" component (reusable-components/00 — Design System).
 *
 * Extracted geometry: primary fill `#4B5EF5`, radius 9, height 42 (md).
 * Labels are DM Sans SemiBold; secondary carries a 1px `#E3E7F0` hairline.
 * Variants read semantic CSS tokens (`--accent*`, `--danger*`) so the whole
 * control set follows the theme, while keeping the Figma brand as the default.
 */
export default function Button({
    variant = 'primary',
    size = 'md',
    loading = false,
    className = '',
    children,
    ...props
}) {
    const variants = {
        primary:
            'bg-[var(--accent)] text-[var(--accent-contrast)] hover:bg-[var(--accent-hover)] active:bg-[var(--accent-active)] focus-visible:ring-[var(--accent-ring)]',
        secondary:
            'bg-[var(--card-bg)] text-ink border border-[var(--border-hairline)] hover:bg-[var(--surface-elevated)] active:bg-[var(--surface-elevated)] focus-visible:ring-[var(--accent-ring)]',
        danger:
            'bg-[var(--danger)] text-[var(--danger-contrast)] hover:bg-[var(--danger-hover)] active:bg-[var(--danger-active)] focus-visible:ring-[var(--danger-ring)]',
        warning:
            'bg-[var(--status-warning)] text-white hover:opacity-90 active:opacity-100 focus-visible:ring-[var(--status-warning)]/40',
        ghost:
            'bg-transparent text-muted border border-[var(--border-hairline)] hover:bg-[var(--surface-elevated)] hover:text-ink focus-visible:ring-[var(--accent-ring)]',
        subtle:
            'bg-[var(--accent-soft)] text-[var(--accent-soft-text)] hover:bg-[var(--accent-soft-hover)] focus-visible:ring-[var(--accent-ring)]',
    };

    const sizes = {
        xs: 'h-[33px] px-3.5 text-[12px] gap-1.5',
        sm: 'h-9 px-3.5 text-[12px] gap-1.5',
        md: 'h-[42px] px-4 text-[13px] gap-2',
        lg: 'h-[46px] px-5 text-[14px] gap-2',
    };

    return (
        <button
            className={`inline-flex items-center justify-center rounded-[9px] font-semibold tracking-[-0.01em] transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50 ${variants[variant] ?? variants.primary} ${sizes[size] ?? sizes.md} ${className}`}
            disabled={loading || props.disabled}
            {...props}
        >
            {loading && (
                <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
            )}
            {children}
        </button>
    );
}
