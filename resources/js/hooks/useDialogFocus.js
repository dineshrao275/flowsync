import { useEffect, useRef } from 'react';

const FOCUSABLE =
    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Dialog focus management for Modal and Drawer (structural twins, one
 * rule): on open, focus lands on the first control (falling back to the
 * panel itself); Tab cycles inside while open; Escape closes; on close,
 * focus returns to whatever opened the dialog and the body scroll lock
 * releases. Without the trap, keyboard users tab out behind the overlay
 * into a page they can no longer see.
 *
 * @returns {import('react').RefObject} attach to the `role="dialog"` panel
 */
export default function useDialogFocus(open, onClose) {
    const panelRef = useRef(null);

    useEffect(() => {
        if (!open) return;

        const previouslyFocused = document.activeElement;
        const panel = panelRef.current;
        const focusables = () => (panel ? [...panel.querySelectorAll(FOCUSABLE)] : []);

        const first = focusables()[0] ?? panel;
        if (first && typeof first.focus === 'function') first.focus();

        function onKeyDown(e) {
            if (e.key === 'Escape') {
                onClose();
                return;
            }

            if (e.key !== 'Tab' || !panel) return;

            const items = focusables();

            if (items.length === 0) {
                e.preventDefault();
                return;
            }

            const head = items[0];
            const tail = items[items.length - 1];

            if (e.shiftKey && document.activeElement === head) {
                e.preventDefault();
                tail.focus();
            } else if (!e.shiftKey && document.activeElement === tail) {
                e.preventDefault();
                head.focus();
            }
        }

        document.addEventListener('keydown', onKeyDown);
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = '';
            if (previouslyFocused instanceof HTMLElement) previouslyFocused.focus();
        };
    }, [open, onClose]);

    return panelRef;
}
