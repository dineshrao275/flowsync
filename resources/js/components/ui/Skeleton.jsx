/**
 * Loading placeholder that holds layout while data fetches, so lists and
 * stat grids don't jump when the real rows land. `bg-gray-200` is theme-safe
 * (the dark remap points it at a dark surface). The pulse freezes at full
 * opacity under `prefers-reduced-motion` via the app.css kill-switch.
 */
const shapes = {
    line: 'h-4 w-full rounded',
    text: 'h-3 w-2/3 rounded',
    circle: 'h-10 w-10 shrink-0 rounded-full',
    block: 'h-32 w-full rounded-xl',
};

export default function Skeleton({ variant = 'line', className = '' }) {
    return (
        <span
            role="status"
            aria-label="Loading"
            className={`block animate-pulse bg-gray-200 ${shapes[variant] ?? shapes.line} ${className}`}
        />
    );
}
