export default function Avatar({ name, size = 'md', className = '' }) {
    const sizes = {
        sm: 'h-7 w-7 text-[11px]',
        md: 'h-8 w-8 text-sm',
        lg: 'h-9 w-9 text-sm',
    };

    return (
        <span
            className={`inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white ${sizes[size]} ${className}`}
            style={{ backgroundColor: 'var(--accent)' }}
            title={name}
        >
            {name?.charAt(0).toUpperCase() ?? '?'}
        </span>
    );
}