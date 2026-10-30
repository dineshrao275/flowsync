import { useId, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import useDialogFocus from '../../hooks/useDialogFocus';

const sizes = {
    sm: 'max-w-md',
    md: 'max-w-xl',
    lg: 'max-w-3xl',
};

export default function Modal({ open, onClose, title, subtitle, size = 'md', children, className = '' }) {
    const { t } = useTranslation();
    const panelRef = useDialogFocus(open, onClose);
    const titleId = useId();
    // A press that starts inside the panel (e.g. selecting text) and ends on the
    // backdrop must not dismiss the dialog, so remember where it began.
    const pressedBackdrop = useRef(false);

    if (!open) return null;

    // Portalled to <body> so the overlay always sits above page content: the
    // page wrapper runs a transform animation, which makes it a containing
    // block/stacking context that would otherwise trap a nested `fixed` modal.
    return createPortal(
        <div
            className="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-gray-900/40 p-0 animate-backdrop-in sm:p-6"
            onMouseDown={(e) => { pressedBackdrop.current = e.target === e.currentTarget; }}
            onClick={(e) => {
                if (pressedBackdrop.current && e.target === e.currentTarget) onClose?.();
                pressedBackdrop.current = false;
            }}
            role="presentation"
        >
            <div
                className={`w-full ${sizes[size]} max-h-dvh overflow-y-auto border border-line shadow-popover animate-scale-in sm:my-6 sm:max-h-[calc(100dvh-3rem)] sm:rounded-xl ${className}`}
                style={{ backgroundColor: 'var(--card-bg)' }}
                role="dialog"
                aria-modal="true"
                aria-labelledby={title ? titleId : undefined}
                ref={panelRef}
                tabIndex={-1}
            >
                {(title || onClose) && (
                    <div className="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-4">
                        <div>
                            {title && <h3 id={titleId} className="text-base font-semibold text-gray-900">{title}</h3>}
                            {subtitle && <p className="mt-0.5 text-sm text-gray-500">{subtitle}</p>}
                        </div>
                        {onClose && (
                            <button
                                type="button"
                                onClick={onClose}
                                aria-label={t('admin.common.close')}
                                className="-mr-1 -mt-1 rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
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