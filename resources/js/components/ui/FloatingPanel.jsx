import { forwardRef, useLayoutEffect, useState } from 'react';
import { createPortal } from 'react-dom';

const GAP = 6;

/**
 * A popover rendered into document.body at fixed coordinates taken from its anchor, so no table
 * wrapper, card or modal with `overflow` can clip it. Flips above the anchor when there is no
 * room below, and follows the anchor on scroll/resize.
 */
const FloatingPanel = forwardRef(function FloatingPanel({ anchorRef, align = 'right', matchWidth = false, className = '', children, ...rest }, ref) {
    const [style, setStyle] = useState({ position: 'fixed', top: 0, left: 0, visibility: 'hidden' });

    useLayoutEffect(() => {
        const place = () => {
            const anchor = anchorRef.current;
            if (!anchor) return;
            const rect = anchor.getBoundingClientRect();
            const panel = typeof ref === 'object' && ref?.current;
            const height = panel?.offsetHeight ?? 0;
            const width = matchWidth ? rect.width : panel?.offsetWidth ?? 0;
            const below = rect.bottom + GAP;
            const top = below + height > window.innerHeight && rect.top - GAP - height > 0 ? rect.top - GAP - height : below;
            const left = align === 'right' ? Math.max(8, rect.right - width) : Math.min(rect.left, window.innerWidth - width - 8);
            setStyle({ position: 'fixed', top, left, visibility: 'visible', ...(matchWidth ? { width: rect.width } : {}) });
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
        <div ref={ref} style={style} className={`z-[90] ${className}`} {...rest}>{children}</div>,
        document.body,
    );
});

export default FloatingPanel;
