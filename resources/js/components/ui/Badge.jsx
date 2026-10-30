const palette = {
    admin: 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]',
    manager: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    editor: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    viewer: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    employee: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    member: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
};

// Semantic tones read the colour-role tokens (app.css), so they stay AA in
// light and dark without a per-tone dark: variant.
export const TONES = {
    accent: 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]',
    neutral: 'bg-surface-sunken text-fg-muted ring-1 ring-inset ring-line',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-[var(--danger-soft)] text-[var(--danger)] dark:bg-red-950/60 dark:text-red-400',
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
