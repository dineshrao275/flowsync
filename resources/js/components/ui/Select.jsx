import { fieldClass } from './fieldStyles';

export default function Select({ label, error, id, className = '', children, ...props }) {
    const selectId = id || props.name;

    return (
        <div className={className}>
            {label && (
                <label htmlFor={selectId} className="mb-1.5 block text-[12px] font-semibold text-ink">
                    {label}
                </label>
            )}
            <select
                id={selectId}
                className={`${fieldClass} ${error ? 'border-[var(--danger)] focus:border-[var(--danger)] focus:ring-[var(--danger-ring)]/50' : ''}`}
                {...props}
            >
                {children}
            </select>
            {error && <p className="mt-1.5 text-[12px] text-[var(--danger)]">{error}</p>}
        </div>
    );
}
