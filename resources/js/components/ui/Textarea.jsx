import { forwardRef, useId } from 'react';
import { fieldClass } from './fieldStyles';

/**
 * Styled <textarea>. Inside FormField it stays a bare control; with its own `label`/`error`
 * (as `Input` and `Select` allow) it renders them, so a call site never ends up with the text
 * leaked onto the DOM node as unknown attributes and no visible label.
 */
const Textarea = forwardRef(function Textarea({ className = '', rows = 4, label, error, id, ...props }, ref) {
    const generatedId = useId();
    const fieldId = id || generatedId;
    const control = (
        <textarea
            ref={ref}
            id={label || error ? fieldId : id}
            rows={rows}
            aria-invalid={error ? 'true' : props['aria-invalid']}
            className={`${fieldClass} resize-y aria-[invalid=true]:border-danger-fg ${className}`}
            {...props}
        />
    );

    if (!label && !error) return control;

    return (
        <div>
            {label && <label htmlFor={fieldId} className="mb-1.5 block text-sm font-medium text-gray-700">{label}</label>}
            {control}
            {error && <p className="mt-1.5 text-sm text-red-600">{error}</p>}
        </div>
    );
});

export default Textarea;
