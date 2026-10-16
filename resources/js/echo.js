import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import logger from './utils/logger';
import { channelAuthorizer, watchReconnects } from './utils/realtimeAuth';

window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? 'localhost',
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        authorizer: channelAuthorizer,
        withCredentials: true,
    });

    if (window.Echo?.connector?.pusher?.connection) {
        const connection = window.Echo.connector.pusher.connection;

        watchReconnects(connection);

        connection.bind('state_change', (states) => {
            logger.info('Reverb', `WebSocket state: ${states.previous} -> ${states.current}`);
        });

        connection.bind('connected', () => {
            logger.info('Reverb', `WebSocket connected to ${import.meta.env.VITE_REVERB_HOST ?? 'localhost'}:${import.meta.env.VITE_REVERB_PORT ?? 8080}`);
        });

        connection.bind('disconnected', () => {
            logger.warn('Reverb', 'WebSocket disconnected');
        });

        connection.bind('error', (err) => {
            logger.error('Reverb', 'WebSocket connection error', err);
        });
    }
}

export default window.Echo ?? null;