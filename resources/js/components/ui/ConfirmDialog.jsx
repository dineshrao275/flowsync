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

function VariantIcon({ variant }) {
    if (variant === 'danger') {
        return (
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 ring-8 ring-red-50/50">
                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
            </div>
        );
    }
    if (variant === 'warning') {
        return (
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600 ring-8 ring-amber-50/50">
                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
            </div>
        );
    }
    return (
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-600 ring-8 ring-indigo-50/50">
            <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10" />
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
        </div>
    );
}

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
            <div className="px-6 py-5">
                <div className="flex items-start gap-3.5">
                    <VariantIcon variant={variant} />
                    <div className="min-w-0 flex-1 pt-0.5">
                        {message && (
                            <p className="text-sm leading-relaxed text-gray-600">
                                {message}
                            </p>
                        )}
                    </div>
                </div>
            </div>
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
