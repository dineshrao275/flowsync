const palette = {
    admin: 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]',
    editor: 'bg-amber-100 text-amber-700',
    viewer: 'bg-sky-100 text-sky-700',
};

// Semantic tones read the colour-role tokens (app.css), so they stay AA in
// light and dark without a per-tone dark: variant.
export const TONES = {
    accent: 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]',
    neutral: 'bg-surface-sunken text-fg-muted ring-1 ring-inset ring-line',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger-fg',
    info: 'bg-info-soft text-info',
};

export default function Badge({ children, tone, className = '', ...props }) {
    // An explicit `tone` wins; without one the colour is derived from the
    // label (role slugs) for the call sites that predate tones.
    const colors = TONES[tone] ?? palette[String(children).toLowerCase()] ?? 'bg-gray-100 text-gray-700';

    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${colors} ${className}`}
            {...props}
        >
            {children}
        </span>
    );
}
