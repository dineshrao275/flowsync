/**
 * Avatar — circular initials chip. The fill is derived deterministically from
 * the name (a small Figma-aligned palette) so the same person is the same
 * colour everywhere; falls back to the brand accent.
 */
const PALETTE = ['#4b5ef5', '#7858de', '#25a6a1', '#d58a16', '#1f9b69', '#d94e61'];

function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function colorFor(name) {
    const s = String(name || '');
    let hash = 0;
    for (let i = 0; i < s.length; i += 1) hash = (hash * 31 + s.charCodeAt(i)) >>> 0;
    return PALETTE[hash % PALETTE.length];
}

export default function Avatar({ name, size = 'md', className = '' }) {
    const sizes = {
        xs: 'h-6 w-6 text-[10px]',
        sm: 'h-7 w-7 text-[11px]',
        md: 'h-8 w-8 text-[12px]',
        lg: 'h-9 w-9 text-[13px]',
        xl: 'h-11 w-11 text-[15px]',
    };

    return (
        <span
            className={`inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white ${sizes[size] ?? sizes.md} ${className}`}
            style={{ backgroundColor: colorFor(name) }}
            title={name}
        >
            {initials(name)}
        </span>
    );
}
