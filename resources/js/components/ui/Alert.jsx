export default function Alert({ type = 'error', children }) {
    const styles = {
        error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/10',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/10',
        info: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950/10',
    };

    if (!children) return null;

    return (
        <div className={`animate-fade-in-up rounded-lg border px-4 py-3 text-sm ${styles[type]}`} role="alert">
            {children}
        </div>
    );
}
