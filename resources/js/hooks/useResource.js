import { useCallback, useEffect, useRef, useState } from 'react';

const isAbort = (err) =>
    err?.name === 'AbortError' || err?.name === 'CanceledError' || err?.code === 'ERR_CANCELED';

/**
 * Fetch-on-mount / on-deps-change resource hook. Replaces the bespoke
 * `useState(data/loading/error)` + `useEffect(() => api.get(...))` block that
 * every list and detail page hand-rolled.
 *
 * `fetcher(signal)` must return a promise (an axios call or any thenable) and
 * should forward `signal` to the request so the in-flight call is cancelled
 * when the component unmounts or `deps` change. `deps` must keep a stable
 * length across renders (pass literals, not a spread array).
 *
 * Returns `{ data, setData, loading, error, reload }`:
 *   data    — the resolved value (null until the first success)
 *   setData — local override (optimistic updates, patching one row)
 *   loading — true during the initial load and every refetch
 *   error   — the rejection (null on success); aborts are swallowed
 *   reload  — re-run the fetcher without changing deps
 */
export function useResource(fetcher, deps = []) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [nonce, setNonce] = useState(0);

    const fetcherRef = useRef(fetcher);
    fetcherRef.current = fetcher;

    useEffect(() => {
        const controller = new AbortController();
        let active = true;

        setLoading(true);
        setError(null);

        Promise.resolve(fetcherRef.current(controller.signal))
            .then((result) => {
                if (active) setData(result);
            })
            .catch((err) => {
                if (active && !isAbort(err)) setError(err);
            })
            .finally(() => {
                if (active) setLoading(false);
            });

        return () => {
            active = false;
            controller.abort();
        };
        // deps is caller-owned; `nonce` forces a reload().
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [...deps, nonce]);

    const reload = useCallback(() => setNonce((n) => n + 1), []);

    return { data, setData, loading, error, reload };
}

/**
 * Paginated variant of `useResource`. `fetchPage(page, signal)` resolves the
 * response payload; the `page` state is owned here and refetches on change.
 * Returns everything `useResource` does, plus `page` / `setPage`.
 */
export function usePaginated(fetchPage, deps = []) {
    const [page, setPage] = useState(1);
    const { data, setData, loading, error, reload } = useResource(
        (signal) => fetchPage(page, signal),
        [...deps, page],
    );

    return { data, setData, loading, error, reload, page, setPage };
}

export default useResource;
