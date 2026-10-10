const palette = {
    admin: 'bg-[#F5F0EB] text-[#1C1917] dark:bg-[#1E2638] dark:text-[#F8FAFC]',
    editor: 'bg-[#F5F0EB] text-[#1C1917] dark:bg-[#1E2638] dark:text-[#F8FAFC]',
    viewer: 'bg-[#F5F0EB] text-[#1C1917] dark:bg-[#1E2638] dark:text-[#F8FAFC]',
};

export default function Badge({ children, tone, className = '', ...props }) {
    // `tone="accent"` follows the theme's accent color (see theme.js tokens);
    // otherwise the color is derived from the label (role slugs).
    const colors = tone === 'accent'
        ? 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
        : palette[String(children).toLowerCase()] || 'bg-gray-100 text-gray-700';

    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${colors} border border-[var(--border-hairline)] dark:border-[#2F3A4C] ${className}`}
            {...props}
        >
            {children}
        </span>
    );
}
