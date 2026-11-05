import { useEffect, useState } from 'react';

/**
 * The value, but only after it stopped changing for `delay` ms. Feed it to the fetch effect so a
 * live search box costs one request per pause instead of one per keystroke.
 */
export default function useDebouncedValue(value, delay = 300) {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), delay);

        return () => window.clearTimeout(timer);
    }, [value, delay]);

    return debounced;
}
