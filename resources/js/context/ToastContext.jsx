import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';

const ToastContext = createContext(null);

const styles = {
    success: {
        primary: '#16a34a',
        soft: 'bg-emerald-50',
        border: 'border-l-emerald-500',
        icon: 'M5 13l4 4L19 7',
    },
    info: {
        primary: '#2563eb',
        soft: 'bg-blue-50',
        border: 'border-l-blue-500',
        icon: 'M12 8h.01M12 12v4',
    },
    warning: {
        primary: '#d97706',
        soft: 'bg-amber-50',
        border: 'border-l-amber-500',
        icon: 'M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
    },
    error: {
        primary: '#dc2626',
        soft: 'bg-red-50',
        border: 'border-l-red-500',
        icon: 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2',
    },
};

function ToastItem({ toast, onClose }) {
    const config = styles[toast.type] || styles.info;

    return (
        <div
            className={`pointer-events-auto relative flex w-full items-start gap-3 rounded-lg border border-l-4 ${config.border} ${config.soft} px-4 py-3 shadow-popover ${
                toast.leaving ? 'animate-toast-out' : 'animate-toast-in'
            }`}
            role="status"
        >
            <svg
                className="mt-0.5 h-5 w-5 shrink-0"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.2"
                strokeLinecap="round"
                strokeLinejoin="round"
                style={{ color: config.primary }}
            >
                <path d={config.icon} />
            </svg>

            <p className="min-w-0 flex-1 text-sm font-medium leading-snug text-gray-800">{toast.message}</p>

            <button
                onClick={onClose}
                className="-mr-1 -mt-0.5 shrink-0 rounded-md p-1 text-gray-400 transition-colors hover:bg-black/5 hover:text-gray-600"
                aria-label="Dismiss notification"
            >
                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>
    );
}

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const counter = useRef(0);

    const dismiss = useCallback((id) => {
        setToasts((current) => current.map((toast) => (toast.id === id ? { ...toast, leaving: true } : toast)));

        window.setTimeout(() => {
            setToasts((current) => current.filter((toast) => toast.id !== id));
        }, 300);
    }, []);

    const push = useCallback(
        (type, message) => {
            counter.current += 1;
            const id = counter.current;

            setToasts((current) => [...current.slice(-3), { id, type, message }]);
            window.setTimeout(() => dismiss(id), 4200);

            return id;
        },
        [dismiss],
    );

    const success = useCallback((message) => push('success', message), [push]);
    const info = useCallback((message) => push('info', message), [push]);
    const warning = useCallback((message) => push('warning', message), [push]);
    const error = useCallback((message) => push('error', message), [push]);

    const value = useMemo(() => ({ success, info, warning, error }), [success, info, warning, error]);

    return (
        <ToastContext.Provider value={value}>
            {children}

            <div
                className="pointer-events-none fixed right-4 top-20 z-[80] flex w-full max-w-sm flex-col gap-2.5"
                aria-live="polite"
            >
                {toasts.map((toast) => (
                    <ToastItem key={toast.id} toast={toast} onClose={() => dismiss(toast.id)} />
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    const context = useContext(ToastContext);
    if (!context) {
        throw new Error('useToast must be used within a ToastProvider');
    }
    return context;
}