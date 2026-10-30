import { forwardRef, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

const GAP = 4;

/**
 * A popover rendered into document.body at fixed coordinates taken from its anchor, so no table
 * wrapper, card or modal with `overflow` can clip it. Sits just beneath the anchor by default,
 * flips above only when there is insufficient space below, and follows the anchor on scroll/resize.
 */
const FloatingPanel = forwardRef(function FloatingPanel({ anchorRef, align = 'right', matchWidth = false, className = '', children, ...rest }, ref) {
    const [style, setStyle] = useState({ position: 'fixed', top: 0, left: 0, visibility: 'hidden' });
    const innerRef = useRef(null);

    const setRefs = (node) => {
        innerRef.current = node;
        if (typeof ref === 'function') {
            ref(node);
        } else if (ref && typeof ref === 'object') {
            ref.current = node;
        }
    };

    useLayoutEffect(() => {
        const place = () => {
            const anchor = anchorRef.current;
            if (!anchor) return;
            const rect = anchor.getBoundingClientRect();
            const panel = innerRef.current;
            const height = panel?.offsetHeight ?? 0;
            const width = matchWidth ? rect.width : panel?.offsetWidth ?? 0;
            const below = rect.bottom + GAP;
            const spaceBelow = window.innerHeight - below - 8;
            const spaceAbove = rect.top - GAP - 8;
            // Prefer placing directly beneath the anchor; flip above only when space below is very tight (< 120px) and space above is larger.
            const flipAbove = spaceBelow < 120 && spaceAbove > spaceBelow;
            const top = flipAbove ? Math.max(8, rect.top - GAP - height) : below;
            const left = align === 'right' ? Math.max(8, rect.right - width) : Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
            setStyle({
                position: 'fixed',
                top,
                left,
                visibility: 'visible',
                ...(matchWidth ? { width: rect.width } : {}),
            });
        };
        place();
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [anchorRef, align, matchWidth, ref, children]);

    return createPortal(
        <div ref={setRefs} style={style} className={`z-[9999] ${className}`} {...rest}>{children}</div>,
        document.body,
    );
});

export default FloatingPanel;
