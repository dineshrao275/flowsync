import { useId } from 'react';

/**
 * On/off setting (applies immediately, unlike a checkbox in a form that is
 * submitted later). `role="switch"` + `aria-checked`; Space/Enter toggle
 * because it is a real <button>.
 */
export default function Switch({ checked, onChange, label, description, disabled = false, id, className = '' }) {
    const generated = useId();
    const switchId = id ?? generated;

    return (
        <div className={`flex items-start justify-between gap-4 ${className}`}>
            {(label || description) && (
                <label htmlFor={switchId} className="cursor-pointer text-sm">
                    {label && <span className="font-medium text-fg">{label}</span>}
                    {description && <span className="block text-caption text-fg-muted">{description}</span>}
                </label>
            )}
            <button
                id={switchId}
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={`relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)] disabled:cursor-not-allowed disabled:opacity-60 ${checked ? 'bg-[var(--accent)]' : 'bg-line-input'}`}
            >
                <span
                    aria-hidden="true"
                    className={`inline-block h-5 w-5 rounded-full bg-white shadow transition-transform ${checked ? 'translate-x-5' : 'translate-x-0.5'}`}
                />
            </button>
        </div>
    );
}
