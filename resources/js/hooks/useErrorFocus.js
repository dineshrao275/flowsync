import { useEffect } from 'react';

/**
 * After a failed submit, move keyboard focus to the first invalid field so
 * keyboard and screen-reader users land on the problem instead of the top
 * of the form; falls back to the form-level alert when no field carries an
 * error. Inputs advertise validity through `aria-invalid` (see Input.jsx).
 */
export default function useErrorFocus(errors, ref) {
    useEffect(() => {
        if (!errors || Object.keys(errors).length === 0) return;

        const root = ref?.current ?? document;
        const query = (selector) => root.querySelector?.(selector);
        const target = query('[aria-invalid="true"]') ?? query('[role="alert"]');

        target?.focus?.({ preventScroll: true });
        target?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
    }, [errors, ref]);
}
