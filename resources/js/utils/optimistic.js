/**
 * Optimistic mutation: apply `apply(current)` to local state immediately, run
 * the request, and put the previous state back if it fails.
 *
 *   await optimistic(setBoard, (b) => moveCard(b, id, to), () => api.post(...));
 *
 * `setState` is a React state setter (functional form). The request's
 * rejection is re-thrown AFTER the rollback so the caller can toast it; a
 * stale response never overwrites a newer change because the rollback only
 * restores when the state still holds the optimistic value.
 */
export async function optimistic(setState, apply, request) {
    let previous;
    let optimisticValue;

    setState((current) => {
        previous = current;
        optimisticValue = apply(current);

        return optimisticValue;
    });

    try {
        return await request();
    } catch (error) {
        setState((current) => (current === optimisticValue ? previous : current));

        throw error;
    }
}
