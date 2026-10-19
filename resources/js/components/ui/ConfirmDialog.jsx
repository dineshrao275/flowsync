import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Modal from './Modal';
import Button from './Button';

/**
 * Promise-based dialog system, the sanctioned replacement for raw
 * `window.confirm`, `window.prompt`, and `window.alert`.
 * Mount `ConfirmProvider` (or `DialogProvider`) once near the app root and call
 * `useConfirm()`, `usePrompt()`, or `useAlert()` anywhere below it:
 *
 *     const confirm = useConfirm();
 *     if (!(await confirm({ title: 'Delete plan?', message: '…', variant: 'danger' }))) return;
 *
 *     const prompt = usePrompt();
 *     const reason = await prompt({ title: 'Rejection Reason', message: 'Why is this rejected?', required: true });
 *     if (reason === null) return;
 *
 *     const alert = useAlert();
 *     await alert({ title: 'Notice', message: 'Action completed.' });
 */
const DialogContext = createContext(null);

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
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--accent-soft)] text-[var(--accent-soft-text)] ring-8 ring-[var(--accent-soft)]/50">
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
    title,
    message = '',
    confirmLabel,
    cancelLabel,
    variant = 'primary',
    onConfirm,
    onCancel,
}) {
    const { t } = useTranslation();

    return (
        <Modal open={open} onClose={onCancel} title={title ?? t('admin.dialog.areYouSure')} size="sm">
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
                    {cancelLabel ?? t('admin.common.cancel')}
                </Button>
                <Button type="button" variant={variant} onClick={onConfirm}>
                    {confirmLabel ?? t('admin.dialog.confirm')}
                </Button>
            </div>
        </Modal>
    );
}

export function PromptDialog({
    open,
    title,
    message = '',
    defaultValue = '',
    placeholder = '',
    confirmLabel,
    cancelLabel,
    variant = 'primary',
    multiline = false,
    required = false,
    onConfirm,
    onCancel,
}) {
    const { t } = useTranslation();
    const [val, setVal] = useState(defaultValue);
    const [touched, setTouched] = useState(false);
    const inputRef = useRef(null);

    useEffect(() => {
        setVal(defaultValue);
        setTouched(false);
    }, [defaultValue, open]);

    useEffect(() => {
        if (!open) return undefined;
        const timer = setTimeout(() => {
            inputRef.current?.focus();
            inputRef.current?.select?.();
        }, 50);

        // Cancel on close/unmount so the callback never fires after teardown.
        return () => clearTimeout(timer);
    }, [open]);

    const isInvalid = required && !val.trim();

    const handleSubmit = (e) => {
        if (e) e.preventDefault();
        setTouched(true);
        if (isInvalid) return;
        onConfirm(val);
    };

    return (
        <Modal open={open} onClose={onCancel} title={title ?? t('admin.dialog.inputRequired')} size="sm">
            <form onSubmit={handleSubmit}>
                <div className="px-6 py-5">
                    <div className="flex items-start gap-3.5">
                        <VariantIcon variant={variant} />
                        <div className="min-w-0 flex-1 pt-0.5 space-y-3">
                            {message && (
                                <p className="text-sm leading-relaxed text-gray-600">
                                    {message}
                                </p>
                            )}
                            {multiline ? (
                                <textarea
                                    ref={inputRef}
                                    rows={3}
                                    value={val}
                                    placeholder={placeholder}
                                    onChange={(e) => setVal(e.target.value)}
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-[var(--accent)] focus:outline-none"
                                />
                            ) : (
                                <input
                                    ref={inputRef}
                                    type="text"
                                    value={val}
                                    placeholder={placeholder}
                                    onChange={(e) => setVal(e.target.value)}
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-[var(--accent)] focus:outline-none"
                                />
                            )}
                            {touched && isInvalid && (
                                <p className="text-xs text-red-600">{t('admin.dialog.required')}</p>
                            )}
                        </div>
                    </div>
                </div>
                <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                    <Button type="button" variant="secondary" onClick={onCancel}>
                        {cancelLabel ?? t('admin.common.cancel')}
                    </Button>
                    <Button type="submit" variant={variant} disabled={required && !val.trim()}>
                        {confirmLabel ?? t('admin.dialog.submit')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export function AlertDialog({
    open,
    title,
    message = '',
    confirmLabel,
    variant = 'primary',
    onClose,
}) {
    const { t } = useTranslation();

    return (
        <Modal open={open} onClose={onClose} title={title ?? t('admin.dialog.notice')} size="sm">
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
                <Button type="button" variant={variant} onClick={onClose}>
                    {confirmLabel ?? t('admin.dialog.ok')}
                </Button>
            </div>
        </Modal>
    );
}

export function ConfirmProvider({ children }) {
    const { t } = useTranslation();
    const [request, setRequest] = useState(null);
    const resolver = useRef(null);

    const confirm = useCallback((options = {}) => {
        return new Promise((resolve) => {
            resolver.current = resolve;
            setRequest({
                type: 'confirm',
                title: options.title ?? t('admin.dialog.areYouSure'),
                message: options.message ?? '',
                confirmLabel:
                    options.confirmLabel ?? (options.variant === 'danger' ? t('admin.common.delete') : t('admin.dialog.confirm')),
                cancelLabel: options.cancelLabel ?? t('admin.common.cancel'),
                variant: options.variant ?? 'primary',
            });
        });
    }, [t]);

    const prompt = useCallback((options = {}, defaultVal = '') => {
        const opts = typeof options === 'string' ? { message: options, defaultValue: defaultVal } : options;
        return new Promise((resolve) => {
            resolver.current = resolve;
            setRequest({
                type: 'prompt',
                title: opts.title ?? t('admin.dialog.inputRequired'),
                message: opts.message ?? '',
                defaultValue: opts.defaultValue ?? '',
                placeholder: opts.placeholder ?? '',
                confirmLabel: opts.confirmLabel ?? t('admin.dialog.submit'),
                cancelLabel: opts.cancelLabel ?? t('admin.common.cancel'),
                variant: opts.variant ?? 'primary',
                multiline: opts.multiline ?? false,
                required: opts.required ?? false,
            });
        });
    }, [t]);

    const alert = useCallback((options = {}) => {
        const opts = typeof options === 'string' ? { message: options } : options;
        return new Promise((resolve) => {
            resolver.current = resolve;
            setRequest({
                type: 'alert',
                title: opts.title ?? t('admin.dialog.notice'),
                message: opts.message ?? '',
                confirmLabel: opts.confirmLabel ?? t('admin.dialog.ok'),
                variant: opts.variant ?? 'primary',
            });
        });
    }, [t]);

    const settle = useCallback((value) => {
        resolver.current?.(value);
        resolver.current = null;
        setRequest(null);
    }, []);

    const value = useMemo(() => ({ confirm, prompt, alert }), [confirm, prompt, alert]);

    return (
        <DialogContext.Provider value={value}>
            {children}
            {request && request.type === 'confirm' && (
                <ConfirmDialog
                    open
                    {...request}
                    onConfirm={() => settle(true)}
                    onCancel={() => settle(false)}
                />
            )}
            {request && request.type === 'prompt' && (
                <PromptDialog
                    open
                    {...request}
                    onConfirm={(val) => settle(val)}
                    onCancel={() => settle(null)}
                />
            )}
            {request && request.type === 'alert' && (
                <AlertDialog
                    open
                    {...request}
                    onClose={() => settle(undefined)}
                />
            )}
        </DialogContext.Provider>
    );
}

export const DialogProvider = ConfirmProvider;

export function useConfirm() {
    const context = useContext(DialogContext);
    if (!context) {
        throw new Error('useConfirm must be used within a ConfirmProvider');
    }
    return context.confirm;
}

export function usePrompt() {
    const context = useContext(DialogContext);
    if (!context) {
        throw new Error('usePrompt must be used within a ConfirmProvider');
    }
    return context.prompt;
}

export function useAlert() {
    const context = useContext(DialogContext);
    if (!context) {
        throw new Error('useAlert must be used within a ConfirmProvider');
    }
    return context.alert;
}
