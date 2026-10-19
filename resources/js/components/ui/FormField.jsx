import { Children, cloneElement, isValidElement, useId } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * Label + control + hint + error wiring in one place. Hand a single control
 * (Input/Select/Textarea/native element) as the child and it receives `id`,
 * `aria-describedby`, `aria-invalid` and `required`, so the label is always
 * associated, the error is announced with the field, and the required marker
 * is consistent (a visual asterisk plus screen-reader text).
 */
export default function FormField({ label, hint, error, required = false, id, children, className = '' }) {
    const { t } = useTranslation();
    const generated = useId();
    const fieldId = id ?? generated;
    const describedBy = [hint && `${fieldId}-hint`, error && `${fieldId}-error`].filter(Boolean).join(' ') || undefined;

    const child = Children.count(children) === 1 && isValidElement(children)
        ? cloneElement(children, {
            id: fieldId,
            'aria-describedby': describedBy,
            'aria-invalid': error ? true : undefined,
            required: required || undefined,
        })
        : children;

    return (
        <div className={className}>
            {label && (
                <label htmlFor={fieldId} className="mb-1.5 block text-sm font-medium text-fg">
                    {label}
                    {required && (
                        <>
                            <span aria-hidden="true" className="ml-0.5 text-danger-fg">*</span>
                            <span className="sr-only"> ({t('admin.common.required')})</span>
                        </>
                    )}
                </label>
            )}
            {child}
            {hint && !error && <p id={`${fieldId}-hint`} className="mt-1.5 text-caption text-fg-muted">{hint}</p>}
            {error && <p id={`${fieldId}-error`} className="mt-1.5 text-caption font-medium text-danger-fg">{error}</p>}
        </div>
    );
}
