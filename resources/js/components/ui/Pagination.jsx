import Button from './Button';

export default function Pagination({
    page,
    pages,
    total,
    label,
    onChange,
    variant = 'secondary',
    placement = 'between',
    size = 'md',
    className = '',
}) {
    if (!pages || pages <= 1) return null;

    const center = (custom) =>
        custom ?? `Page ${page} of ${pages}${total != null ? ` · ${total}` : ''}`;

    const prevBtn = (
        <Button variant={variant} size={size} disabled={page <= 1} onClick={() => onChange(page - 1)}>
            Prev
        </Button>
    );
    const nextBtn = (
        <Button variant={variant} size={size} disabled={page >= pages} onClick={() => onChange(page + 1)}>
            Next
        </Button>
    );

    if (placement === 'sides') {
        return (
            <div className={`flex items-center justify-between text-sm ${className}`}>
                <span className="text-gray-400">{center(label)}</span>
                <div className="flex gap-2">
                    {prevBtn}
                    {nextBtn}
                </div>
            </div>
        );
    }

    return (
        <div className={`flex items-center justify-between ${className}`}>
            {prevBtn}
            <span className="text-sm text-gray-500">{center(label)}</span>
            {nextBtn}
        </div>
    );
}