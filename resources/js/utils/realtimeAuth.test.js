import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RECONNECTED_EVENT, channelAuthorizer, onRealtimeReconnect, watchReconnects } from './realtimeAuth';

const post = vi.fn();
vi.mock('../services/api', () => ({ default: { post: (...args) => post(...args) } }));

function fakeConnection() {
    let handler;

    return { bind: (_event, fn) => { handler = fn; }, emit: (current) => handler({ current }) };
}

describe('channelAuthorizer', () => {
    beforeEach(() => { post.mockReset(); });

    it('posts the socket id and channel through axios (fresh XSRF cookie) to the root-level auth route', async () => {
        post.mockResolvedValue({ data: { auth: 'key:sig' } });
        const callback = vi.fn();

        channelAuthorizer({ name: 'private-user.2' }).authorize('1.2', callback);
        await vi.waitFor(() => expect(callback).toHaveBeenCalled());

        expect(post).toHaveBeenCalledWith('/broadcasting/auth', { socket_id: '1.2', channel_name: 'private-user.2' }, { baseURL: '/' });
        expect(callback).toHaveBeenCalledWith(null, { auth: 'key:sig' });
    });

    it('reports a failed authorization to pusher', async () => {
        const failure = new Error('403');
        post.mockRejectedValue(failure);
        const callback = vi.fn();

        channelAuthorizer({ name: 'private-project.9' }).authorize('1.2', callback);
        await vi.waitFor(() => expect(callback).toHaveBeenCalledWith(failure, null));
    });
});

describe('reconnect signal', () => {
    it('fires only when the socket comes back after a drop, not on first connect', () => {
        const connection = fakeConnection();
        const target = new EventTarget();
        const seen = vi.fn();
        watchReconnects(connection, target);
        const stop = onRealtimeReconnect(seen, target);

        connection.emit('connecting');
        connection.emit('connected');
        expect(seen).not.toHaveBeenCalled();

        connection.emit('unavailable');
        connection.emit('connecting');
        connection.emit('connected');
        expect(seen).toHaveBeenCalledTimes(1);

        stop();
        connection.emit('disconnected');
        connection.emit('connected');
        expect(seen).toHaveBeenCalledTimes(1);
        expect(RECONNECTED_EVENT).toBe('flowsync:realtime-reconnected');
    });
});
