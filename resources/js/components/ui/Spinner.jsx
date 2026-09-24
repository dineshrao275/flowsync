export default function Spinner({ className = '' }) {
    return (
        <div
            className={`h-8 w-8 animate-spin rounded-full border-2 border-gray-300 border-t-indigo-600 ${className}`}
            role="status"
            aria-label="Loading"
        >
            <span className="sr-only">Loading…</span>
        </div>
    );
}
