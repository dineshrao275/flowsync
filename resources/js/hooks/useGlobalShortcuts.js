import { useEffect, useRef } from 'react';

const TYPING = 'input, textarea, select, [contenteditable="true"], [contenteditable=""]';

/**
 * Single-key app shortcuts (WCAG 2.1.4 compliant: they only fire outside text
 * fields, never with a modifier held, and `?` lists them all).
 *
 *   /        open search
 *   ?        open the shortcuts sheet
 *   g + key  go to a section (`bindings.go[key]` => callback), 1s window
 *
 * Disabled while any dialog is open so typing into a modal never navigates.
 */
export default function useGlobalShortcuts({ onSearch, onHelp, go = {} }) {
    const handlers = useRef({ onSearch, onHelp, go });
    handlers.current = { onSearch, onHelp, go };

    useEffect(() => {
        let pendingG = null;

        function onKeyDown(event) {
            if (event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.target instanceof Element && event.target.closest(TYPING)) return;
            if (document.querySelector('[role="dialog"][aria-modal="true"]')) return;

            const { onSearch: search, onHelp: help, go: routes } = handlers.current;

            if (pendingG) {
                window.clearTimeout(pendingG);
                pendingG = null;
                const target = routes[event.key.toLowerCase()];
                if (target) {
                    event.preventDefault();
                    target();
                }
                return;
            }

            if (event.key === '/' && search) {
                event.preventDefault();
                search();
            } else if (event.key === '?' && help) {
                event.preventDefault();
                help();
            } else if (event.key.toLowerCase() === 'g' && Object.keys(routes).length > 0) {
                pendingG = window.setTimeout(() => { pendingG = null; }, 1000);
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);
}
