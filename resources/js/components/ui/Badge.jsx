const palette = {
    admin: 'bg-indigo-100 text-indigo-700',
    editor: 'bg-amber-100 text-amber-700',
    viewer: 'bg-sky-100 text-sky-700',
};

export default function Badge({ children, tone, className = '', ...props }) {
    // `tone="accent"` follows the theme's accent color (see theme.js tokens);
    // otherwise the color is derived from the label (role slugs).
    const colors = tone === 'accent'
        ? 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
        : palette[String(children).toLowerCase()] || 'bg-gray-100 text-gray-700';

    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${colors} ${className}`}
            {...props}
        >
            {children}
        </span>
    );
}
