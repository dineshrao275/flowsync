/**
 * Badge — small role/tag chip. Maps known role slugs to a neutral Figma chip;
 * `tone="accent"` follows the theme accent, otherwise the label drives a
 * deterministic tint from the Figma soft palette.
 */
const TONES = {
    admin: 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]',
    owner: 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]',
    editor: 'bg-[var(--status-info-soft)] text-[var(--status-info)]',
    manager: 'bg-[var(--status-purple-soft)] text-[var(--status-purple)]',
    hr_manager: 'bg-[var(--status-teal)]/12 text-[var(--status-teal)]',
    viewer: 'bg-[var(--track)] text-[var(--text-secondary)]',
};

export default function Badge({ children, tone, className = '', ...props }) {
    const colors =
        tone === 'accent'
            ? 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
            : TONES[String(children).toLowerCase()] || 'bg-[var(--track)] text-[var(--text-secondary)]';

    return (
        <span
            className={`inline-flex items-center rounded-[7px] px-2 py-0.5 text-[11px] font-semibold leading-none ${colors} ${className}`}
            {...props}
        >
            {children}
        </span>
    );
}
