/**
 * Button variants read the theme's accent tokens (`--accent*`, derived in
 * resources/js/theme.js from the admin's `accent` color) instead of a fixed
 * indigo, so the whole control set follows the theme. Destructive actions
 * read the parallel `--danger*` tokens, which step brighter in dark mode so
 * red buttons stay distinct on dark surfaces.
 *
 * Focus converges on the global `:focus-visible` outline (app.css) tinted
 * per variant — no box-shadow ring, so keyboard focus is one indicator.
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
            'bg-[var(--accent)] text-[var(--accent-contrast)] shadow-sm hover:bg-[var(--accent-hover)] active:bg-[var(--accent-active)] focus-visible:outline-[var(--accent)]',
        secondary:
            'bg-white text-gray-700 border border-gray-300 shadow-sm hover:bg-gray-50 hover:text-gray-900 focus-visible:outline-gray-400',
        danger:
            'bg-[var(--danger)] text-[var(--danger-contrast)] shadow-sm hover:bg-[var(--danger-hover)] active:bg-[var(--danger-active)] focus-visible:outline-[var(--danger)]',
        warning:
            'bg-amber-400 text-[#1c1100] shadow-sm hover:bg-amber-300 active:bg-amber-500 focus-visible:outline-amber-500',
        ghost:
            'bg-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-gray-400',
        subtle:
            'bg-[var(--accent-soft)] text-[var(--accent-soft-text)] hover:bg-[var(--accent-soft-hover)] focus-visible:outline-[var(--accent)]',
    };

    const sizes = {
        sm: 'px-3 py-1.5 text-xs',
        md: 'px-4 py-2.5 text-sm',
        lg: 'px-5 py-3 text-sm',
        // Square, icon-only control; always pass an accessible name via aria-label.
        icon: 'h-10 w-10 p-0 text-sm',
    };

    return (
        <button
            className={`inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-all duration-150 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100 ${variants[variant] ?? variants.primary} ${sizes[size] ?? sizes.md} ${className}`}
            disabled={loading || props.disabled}
            aria-busy={loading || undefined}
            data-loading={loading || undefined}
            {...props}
        >
            {loading && (
                <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />
            )}
            {children}
        </button>
    );
}
