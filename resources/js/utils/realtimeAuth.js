import api from '../services/api';

export const RECONNECTED_EVENT = 'flowsync:realtime-reconnected';

/**
 * Channel authorizer for Echo/pusher-js. Posting through the shared axios
 * instance (instead of pusher-js's own XHR) means the request carries the
 * CURRENT `XSRF-TOKEN` cookie. pusher-js's built-in auth sends the CSRF
 * token that was baked into the page's `<meta>` at load time; that token is
 * regenerated when the SPA signs in, so after a login without a reload every
 * private-channel subscription answered 419 and realtime silently never started.
 */
export function channelAuthorizer(channel) {
    return {
        authorize(socketId, callback) {
            api.post(
                '/broadcasting/auth',
                { socket_id: socketId, channel_name: channel.name },
                { baseURL: '/' },
            )
                .then((response) => callback(null, response.data))
                .catch((error) => callback(error, null));
        },
    };
}

/**
 * Tell the app the socket came back after an outage. Events pushed while it
 * was down are not replayed, so listeners refetch (see NotificationContext,
 * ProjectDetail, CommentThread, the support pages).
 */
export function watchReconnects(connection, target = window) {
    let wasConnected = false;
    let dropped = false;

    connection.bind('state_change', ({ current }) => {
        if (current === 'connected') {
            if (wasConnected && dropped) target.dispatchEvent(new Event(RECONNECTED_EVENT));
            wasConnected = true;
            dropped = false;
        } else if (wasConnected) {
            dropped = true;
        }
    });
}

/** Subscribe to the reconnect signal; returns the unsubscribe function. */
export function onRealtimeReconnect(handler, target = window) {
    target.addEventListener(RECONNECTED_EVENT, handler);

    return () => target.removeEventListener(RECONNECTED_EVENT, handler);
}
