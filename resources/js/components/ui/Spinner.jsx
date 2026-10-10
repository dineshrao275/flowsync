export default function Spinner({ className = '' }) {
    return (
        <div
            className={`h-8 w-8 animate-spin rounded-full border-2 border-gray-200 dark:border-[#2F3A4C] border-t-[var(--accent)] ${className}`}
            role="status"
            aria-label="Loading"
        >
            <span className="sr-only">Loading…</span>
        </div>
    );
}
