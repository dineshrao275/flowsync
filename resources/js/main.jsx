import './bootstrap';
import './i18n';
import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import logger from './utils/logger';

// Catch and log uncaught exceptions across the SPA
if (typeof window !== 'undefined') {
    window.addEventListener('error', (event) => {
        logger.error('GlobalError', event.message || 'Uncaught window error', {
            filename: event.filename,
            lineno: event.lineno,
            colno: event.colno,
            error: event.error?.stack || event.error,
        });
    });

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;
        logger.error('UnhandledPromise', reason?.message || 'Unhandled promise rejection', {
            stack: reason?.stack,
            reason,
        });
    });
}

createRoot(document.getElementById('root')).render(
    <React.StrictMode>
        <App />
    </React.StrictMode>,
);

// F12: offline app shell. Production only — a worker in dev would serve stale Vite modules.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/app/' }).catch(() => {});
    });
}
