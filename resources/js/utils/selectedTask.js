/**
 * Applies a re-fetched task to the open tray ONLY if that same task is still the one open.
 *
 * A realtime `task.synced` event (fired by our own save) starts a refetch of the open task. If the tray is
 * closed before that response lands, writing it blindly re-opens the tray: the "closes on save, then pops
 * back" bug. Used as a functional state update so the check sees the state at write time, not at fetch time.
 */
export function refreshSelected(current, fresh) {
    return current && fresh && current.id === fresh.id ? fresh : current;
}
