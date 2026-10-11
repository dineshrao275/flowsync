import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import Modal from './Modal';
import Button from './Button';

/**
 * Promise-based confirmation dialog, the sanctioned replacement for raw
 * `window.confirm` (roadmap P1.12 / Definition of Done: "no raw
 * window.confirm"). Mount `ConfirmProvider` once near the app root and call
 * `useConfirm()` anywhere below it:
 *
 *     const confirm = useConfirm();
 *     if (!(await confirm({ title: 'Delete plan?', message: '…', variant: 'danger' }))) return;
 *
 * The promise resolves `true` on confirm and `false` on cancel/dismiss, so the
 * call site reads like the native dialog it replaces but renders an in-app modal.
 */
const ConfirmContext = createContext(null);

export function ConfirmDialog({
    open,
    title = 'Are you sure?',
    message = '',
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    variant = 'primary',
    onConfirm,
    onCancel,
}) {
    return (
        <Modal open={open} onClose={onCancel} title={title} size="sm">
            {message && <p className="px-6 py-5 text-sm text-gray-600">{message}</p>}
            <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                <Button type="button" variant="secondary" onClick={onCancel}>
                    {cancelLabel}
                </Button>
                <Button type="button" variant={variant} onClick={onConfirm}>
                    {confirmLabel}
                </Button>
            </div>
        </Modal>
    );
}

export function ConfirmProvider({ children }) {
    const [request, setRequest] = useState(null);
    const resolver = useRef(null);

    const confirm = useCallback((options = {}) => {
        return new Promise((resolve) => {
            resolver.current = resolve;
            setRequest({
                title: options.title ?? 'Are you sure?',
                message: options.message ?? '',
                confirmLabel:
                    options.confirmLabel ?? (options.variant === 'danger' ? 'Delete' : 'Confirm'),
                cancelLabel: options.cancelLabel ?? 'Cancel',
                variant: options.variant ?? 'primary',
            });
        });
    }, []);

    const settle = useCallback((value) => {
        resolver.current?.(value);
        resolver.current = null;
        setRequest(null);
    }, []);

    const value = useMemo(() => ({ confirm }), [confirm]);

    return (
        <ConfirmContext.Provider value={value}>
            {children}
            {request && (
                <ConfirmDialog
                    open
                    {...request}
                    onConfirm={() => settle(true)}
                    onCancel={() => settle(false)}
                />
            )}
        </ConfirmContext.Provider>
    );
}

export function useConfirm() {
    const context = useContext(ConfirmContext);
    if (!context) {
        throw new Error('useConfirm must be used within a ConfirmProvider');
    }
    return context.confirm;
}
