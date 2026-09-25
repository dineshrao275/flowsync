import { useEffect } from 'react';
import { createPortal } from 'react-dom';

export default function Drawer({ open, onClose, title, subtitle, children, headerExtras, footer, className = '' }) {
    useEffect(() => {
        if (!open) return;

        function onKey(e) {
            if (e.key === 'Escape') onClose();
        }

        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [open, onClose]);

    if (!open) return null;

    // Portalled to <body> so the panel always paints above page content: the
    // page wrapper runs a transform animation, which makes it a containing
    // block/stacking context that would otherwise trap a nested `fixed` panel.
    return createPortal(
        <div
            className="fixed inset-0 z-50 flex justify-end bg-gray-900/30 animate-backdrop-in"
            onClick={onClose}
            role="presentation"
        >
            <div
                className={`flex h-full w-full max-w-xl animate-from-right flex-col overflow-hidden border-l border-gray-200 shadow-drawer sm:max-w-3xl ${className}`}
                style={{ backgroundColor: 'var(--card-bg)' }}
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
            >
                {(title || onClose) && (
                    <div className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 px-6 py-4">
                        <div className="min-w-0 flex-1">
                            {title && <h3 className="truncate text-lg font-semibold text-gray-900">{title}</h3>}
                            {subtitle && <p className="mt-0.5 truncate text-xs font-semibold uppercase tracking-wide text-gray-400">{subtitle}</p>}
                            {headerExtras}
                        </div>
                        {onClose && (
                            <button
                                type="button"
                                onClick={onClose}
                                aria-label="Close"
                                className="-mr-1 -mt-1 rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                            >
                                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                                    <path d="M6 6l12 12M18 6L6 18" />
                                </svg>
                            </button>
                        )}
                    </div>
                )}
                <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
                {footer && <div className="shrink-0 border-t border-gray-100 px-6 py-3">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}