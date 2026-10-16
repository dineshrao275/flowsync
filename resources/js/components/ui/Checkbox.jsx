import { useId } from 'react';

/**
 * Checkbox with its label and optional description in one hit target.
 * Uses the native input (keyboard, forms and AT for free) with the theme
 * accent via `accent-color`.
 */
export default function Checkbox({ label, description, id, className = '', ...props }) {
    const generated = useId();
    const inputId = id ?? generated;

    return (
        <div className={`flex items-start gap-3 ${className}`}>
            <input
                id={inputId}
                type="checkbox"
                className="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-line-input accent-[var(--accent)] disabled:cursor-not-allowed disabled:opacity-60"
                aria-describedby={description ? `${inputId}-desc` : undefined}
                {...props}
            />
            {(label || description) && (
                <label htmlFor={inputId} className="cursor-pointer text-sm">
                    {label && <span className="font-medium text-fg">{label}</span>}
                    {description && <span id={`${inputId}-desc`} className="block text-caption text-fg-muted">{description}</span>}
                </label>
            )}
        </div>
    );
}
