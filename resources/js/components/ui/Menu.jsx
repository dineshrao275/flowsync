import { useEffect, useId, useRef, useState } from 'react';
import FloatingPanel from './FloatingPanel';

/**
 * Dropdown action menu (WAI-ARIA menu button): the trigger toggles it,
 * ArrowUp/Down/Home/End move between items, Enter/Space activate, Escape or an
 * outside press closes and returns focus to the trigger. One implementation
 * for the per-row ⋯ menus and the topbar menus instead of hand-rolled ones.
 *
 * `trigger` is a render function `(props) => element` so the caller owns the
 * button's look; spread `props` onto it. `items`: `{ key, label, onSelect,
 * danger?, disabled?, icon? }` (a `{ separator: true }` entry draws a rule).
 */
export default function Menu({ trigger, items, align = 'right', label }) {
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);
    const triggerRef = useRef(null);
    const panelRef = useRef(null);
    const menuId = useId();

    const enabled = () => [...(panelRef.current?.querySelectorAll('[role="menuitem"]:not([disabled])') ?? [])];

    function close(restoreFocus = true) {
        setOpen(false);
        if (restoreFocus) triggerRef.current?.focus();
    }

    useEffect(() => {
        if (!open) return undefined;

        enabled()[0]?.focus();

        const onPointerDown = (event) => {
            if (!rootRef.current?.contains(event.target) && !panelRef.current?.contains(event.target)) setOpen(false);
        };
        document.addEventListener('mousedown', onPointerDown);

        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    function onMenuKeyDown(event) {
        const list = enabled();
        const index = list.indexOf(document.activeElement);

        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            list[(index + 1) % list.length]?.focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            list[(index - 1 + list.length) % list.length]?.focus();
        } else if (event.key === 'Home') {
            event.preventDefault();
            list[0]?.focus();
        } else if (event.key === 'End') {
            event.preventDefault();
            list[list.length - 1]?.focus();
        } else if (event.key === 'Tab') {
            setOpen(false);
        }
    }

    const triggerProps = {
        ref: triggerRef,
        type: 'button',
        'aria-haspopup': 'menu',
        'aria-expanded': open,
        'aria-controls': open ? menuId : undefined,
        'aria-label': label,
        onClick: () => setOpen((value) => !value),
        onKeyDown: (event) => {
            if (event.key === 'ArrowDown' && !open) {
                event.preventDefault();
                setOpen(true);
            }
        },
    };

    return (
        <div ref={rootRef} className="relative inline-block text-left">
            {trigger(triggerProps)}
            {open && (
                <FloatingPanel
                    ref={panelRef}
                    anchorRef={rootRef}
                    align={align}
                    id={menuId}
                    role="menu"
                    aria-label={label}
                    onKeyDown={onMenuKeyDown}
                    className="min-w-44 rounded-xl border border-line bg-surface p-1 shadow-popover animate-scale-in"
                >
                    {items.map((item, index) => (item.separator ? (
                        <div key={`sep-${index}`} role="separator" className="my-1 h-px bg-line" />
                    ) : (
                        <button
                            key={item.key}
                            type="button"
                            role="menuitem"
                            tabIndex={-1}
                            disabled={item.disabled}
                            onClick={() => {
                                setOpen(false);
                                triggerRef.current?.focus();
                                item.onSelect?.();
                            }}
                            className={`flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition focus:outline-none focus-visible:bg-surface-sunken disabled:cursor-not-allowed disabled:opacity-50 ${item.danger ? 'text-danger-fg hover:bg-danger-soft' : 'text-fg hover:bg-surface-sunken'}`}
                        >
                            {item.icon}
                            {item.label}
                        </button>
                    )))}
                </FloatingPanel>
            )}
        </div>
    );
}
