export default function Alert({ type = 'error', children }) {
    const styles = {
        error: 'border-danger-line bg-danger-soft text-danger-fg',
        success: 'border-success-line bg-success-soft text-success',
        warning: 'border-warning-line bg-warning-soft text-warning',
        info: 'border-info-line bg-info-soft text-info',
    };

    if (!children) return null;

    return (
        <div className={`animate-fade-in-up rounded-lg border px-4 py-3 text-sm ${styles[type] ?? styles.error}`} role={type === 'error' ? 'alert' : 'status'} tabIndex={-1}>
            {children}
        </div>
    );
}
