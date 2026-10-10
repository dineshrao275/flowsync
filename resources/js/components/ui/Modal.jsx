import { useId } from 'react';
import { createPortal } from 'react-dom';
import useDialogFocus from '../../hooks/useDialogFocus';

const sizes = {
    sm: 'max-w-md',
    md: 'max-w-xl',
    lg: 'max-w-3xl',
};

export default function Modal({ open, onClose, title, subtitle, size = 'md', children, className = '' }) {
    const panelRef = useDialogFocus(open, onClose);
    const titleId = useId();

    if (!open) return null;

    // Portalled to <body> so the overlay always sits above page content: the
    // page wrapper runs a transform animation, which makes it a containing
    // block/stacking context that would otherwise trap a nested `fixed` modal.
    return createPortal(
        <div
            className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#151b2c]/40 backdrop-blur-[2px] p-4 animate-backdrop-in dark:bg-black/60 sm:p-6"
            onMouseDown={onClose}
            role="presentation"
        >
            <div
                className={`my-6 w-full ${sizes[size]} max-h-[calc(100dvh-3rem)] overflow-y-auto rounded-[16px] border border-[var(--border-hairline)] bg-[var(--card-bg)] shadow-[var(--shadow-popover)] animate-scale-in ${className}`}
                onMouseDown={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby={title ? titleId : undefined}
                ref={panelRef}
                tabIndex={-1}
            >
                {(title || onClose) && (
                    <div className="flex items-start justify-between gap-4 border-b border-[var(--border-hairline)] px-6 py-4">
                        <div className="min-w-0">
                            {title && <h3 id={titleId} className="text-[15px] font-semibold text-ink">{title}</h3>}
                            {subtitle && <p className="mt-0.5 text-[12px] text-muted">{subtitle}</p>}
                        </div>
                        {onClose && (
                            <button
                                type="button"
                                onClick={onClose}
                                aria-label="Close"
                                className="-mr-1 -mt-1 rounded-[8px] p-1.5 text-[var(--text-faint)] transition-colors hover:bg-[var(--surface-elevated)] hover:text-ink"
                            >
                                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                                    <path d="M6 6l12 12M18 6L6 18" />
                                </svg>
                            </button>
                        )}
                    </div>
                )}
                {children}
            </div>
        </div>,
        document.body,
    );
}
