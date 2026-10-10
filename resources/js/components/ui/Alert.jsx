export default function Alert({ type = 'error', children }) {
    const styles = {
        error: 'border-[var(--danger)]/30 bg-[var(--status-danger-soft)] text-[var(--status-danger)]',
        success: 'border-[var(--success)]/30 bg-[var(--status-success-soft)] text-[var(--status-success)]',
        info: 'border-[var(--accent)]/30 bg-[var(--status-info-soft)] text-[var(--status-info)]',
        warning: 'border-[var(--warning)]/30 bg-[var(--status-warning-soft)] text-[var(--status-warning)]',
    };

    if (!children) return null;

    return (
        <div className={`animate-fade-in-up rounded-[10px] border px-4 py-3 text-[12px] ${styles[type] ?? styles.error}`} role="alert">
            {children}
        </div>
    );
}
