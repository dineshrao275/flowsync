import { useEffect } from 'react';

export function useClickOutside(ref, handler) {
    useEffect(() => {
        function listener(event) {
            if (ref.current && !ref.current.contains(event.target)) {
                handler(event);
            }
        }

        document.addEventListener('mousedown', listener);
        document.addEventListener('touchstart', listener);

        return () => {
            document.removeEventListener('mousedown', listener);
            document.removeEventListener('touchstart', listener);
        };
    }, [ref, handler]);
}