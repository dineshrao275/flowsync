import { useEffect, useId, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { ArrowsInSimple, ArrowsOutSimple } from '@phosphor-icons/react';
import useDialogFocus from '../../hooks/useDialogFocus';

export default function Drawer({
    open,
    onClose,
    title,
    subtitle,
    children,
    headerExtras,
    footer,
    className = '',
    allowFullscreen = false,
    fullscreen: controlledFullscreen,
    onToggleFullscreen,
}) {
    const { t } = useTranslation();
    const panelRef = useDialogFocus(open, onClose);
    const titleId = useId();
    const [internalFullscreen, setInternalFullscreen] = useState(false);

    const isFullscreen = controlledFullscreen !== undefined ? controlledFullscreen : internalFullscreen;

    const toggleFullscreen = () => {
        if (onToggleFullscreen) {
            onToggleFullscreen(!isFullscreen);
        } else {
            setInternalFullscreen((prev) => !prev);
        }
    };

    useEffect(() => {
        if (!open) {
            setInternalFullscreen(false);
        }
    }, [open]);

    if (!open) return null;

    // Portalled to <body> so the panel always paints above page content: the
    // page wrapper runs a transform animation, which makes it a containing
    // block/stacking context that would otherwise trap a nested `fixed` panel.
    return createPortal(
        <div
            className={`fixed inset-0 z-50 flex ${isFullscreen ? 'p-0' : 'justify-end bg-gray-900/30'} animate-backdrop-in`}
            onClick={isFullscreen ? undefined : onClose}
            role="presentation"
        >
            <div
                className={`flex h-full w-full flex-col overflow-hidden transition-all duration-200 ease-in-out ${
                    isFullscreen
                        ? 'max-w-none border-l-0 shadow-none'
                        : 'max-w-xl sm:max-w-3xl md:max-w-4xl border-l border-gray-200 shadow-drawer animate-from-right'
                } ${className}`}
                style={{ backgroundColor: 'var(--card-bg)' }}
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby={title ? titleId : undefined}
                ref={panelRef}
                tabIndex={-1}
            >
                {(title || onClose || allowFullscreen) && (
                    <div className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 px-6 py-4">
                        <div className="min-w-0 flex-1">
                            {title && <h3 id={titleId} className="truncate text-lg font-semibold text-gray-900">{title}</h3>}
                            {subtitle && <p className="mt-0.5 truncate text-xs font-semibold uppercase tracking-wide text-gray-400">{subtitle}</p>}
                            {headerExtras}
                        </div>
                        <div className="flex items-center gap-1 -mr-1 -mt-1">
                            {allowFullscreen && (
                                <button
                                    type="button"
                                    onClick={toggleFullscreen}
                                    aria-label={isFullscreen ? t('tms.detail.restoreSize', 'Restore size') : t('tms.detail.fullSize', 'Full size')}
                                    title={isFullscreen ? t('tms.detail.restoreSize', 'Restore size') : t('tms.detail.fullSize', 'Full size')}
                                    className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-slate-800 dark:hover:text-gray-300"
                                >
                                    {isFullscreen ? (
                                        <ArrowsInSimple size={18} aria-hidden="true" />
                                    ) : (
                                        <ArrowsOutSimple size={18} aria-hidden="true" />
                                    )}
                                </button>
                            )}
                            {onClose && (
                                <button
                                    type="button"
                                    onClick={onClose}
                                    aria-label={t('admin.common.close')}
                                    className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-slate-800 dark:hover:text-gray-300"
                                >
                                    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                                        <path d="M6 6l12 12M18 6L6 18" />
                                    </svg>
                                </button>
                            )}
                        </div>
                    </div>
                )}
                <div className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden">{children}</div>
                {footer && <div className="shrink-0 border-t border-gray-100 px-6 py-3">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}