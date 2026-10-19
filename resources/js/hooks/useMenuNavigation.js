import { useEffect, useRef } from 'react';

const ITEMS = '[role="menuitem"]:not([disabled]), [role="menuitemradio"]:not([disabled])';

/**
 * Keyboard behaviour for an open hand-rolled menu (topbar menus whose panel
 * carries extra content, so they cannot use `ui/Menu`): focus the first item
 * on open, Arrow/Home/End roving, Tab closes. Escape + outside-press stay with
 * the caller, which already owns them.
 *
 * @param {boolean} open
 * @param {import('react').RefObject<HTMLElement>} containerRef element containing the items
 * @param {() => void} close
 */
export default function useMenuNavigation(open, containerRef, close) {
    // Callers pass an inline arrow; keep it out of the effect deps so a parent
    // re-render never re-runs the effect and yanks focus back to the first item.
    const closeRef = useRef(close);
    closeRef.current = close;

    useEffect(() => {
        if (!open) return undefined;

        const root = containerRef.current;
        const items = () => [...(root?.querySelectorAll(ITEMS) ?? [])];
        items()[0]?.focus();

        function onKeyDown(event) {
            const list = items();
            if (list.length === 0 || !root?.contains(document.activeElement)) return;
            const index = list.indexOf(document.activeElement);

            if (event.key === 'ArrowDown') list[(index + 1) % list.length].focus();
            else if (event.key === 'ArrowUp') list[(index - 1 + list.length) % list.length].focus();
            else if (event.key === 'Home') list[0].focus();
            else if (event.key === 'End') list[list.length - 1].focus();
            else if (event.key === 'Tab') closeRef.current();
            else return;

            if (event.key !== 'Tab') event.preventDefault();
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [open, containerRef]);
}
