import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';

/**
 * Query-string-backed state for filters, sort and page, so a filtered list
 * survives refresh, can be shared as a link and works with back/forward.
 *
 * `defaults` declares the keys (and their default values, as strings). Values
 * equal to their default are kept OUT of the URL, so an unfiltered page has a
 * clean address. `update(patch)` merges a patch; any change to a key other
 * than `page` resets `page` to its default, which is what every list wants.
 *
 *   const [url, update] = useUrlState({ q: '', status: '', page: '1' });
 *   update({ q: 'bug' });   // ?q=bug   (page reset)
 *   update({ page: '3' });  // ?q=bug&page=3
 */
export default function useUrlState(defaults) {
    const [params, setParams] = useSearchParams();
    const signature = JSON.stringify(defaults);

    const state = useMemo(() => {
        const base = JSON.parse(signature);

        return Object.fromEntries(Object.keys(base).map((key) => [key, params.get(key) ?? base[key]]));
    }, [params, signature]);

    const update = useCallback(
        (patch, { replace = false } = {}) => {
            const base = JSON.parse(signature);

            setParams(
                (previous) => {
                    const next = new URLSearchParams(previous);
                    const touchesFilter = Object.keys(patch).some((key) => key !== 'page');
                    const merged = touchesFilter && !('page' in patch) && 'page' in base ? { ...patch, page: base.page } : patch;

                    Object.entries(merged).forEach(([key, value]) => {
                        if (value === undefined || value === null || String(value) === String(base[key] ?? '')) next.delete(key);
                        else next.set(key, String(value));
                    });

                    return next;
                },
                { replace },
            );
        },
        [setParams, signature],
    );

    const reset = useCallback(() => setParams(new URLSearchParams()), [setParams]);

    return [state, update, reset];
}
