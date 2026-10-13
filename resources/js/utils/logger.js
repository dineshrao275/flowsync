/**
 * FlowSync Client Logger.
 *
 * Provides structured logging across API requests, real-time WebSockets,
 * Error Boundaries, and UI components with formatted console badges and
 * automated error forwarding to Laravel backend logs (/api/logs/client).
 */

const LOG_LEVELS = {
    debug: 0,
    info: 1,
    warn: 2,
    error: 3,
};

// Default log level: debug in development/test, info in production
const isProduction = typeof process !== 'undefined' && process.env?.NODE_ENV === 'production';
let currentLevel = isProduction ? LOG_LEVELS.info : LOG_LEVELS.debug;

// Throttle backend error reports to prevent flooding
const reportedErrors = new Set();
let reportQueue = [];
let flushTimeout = null;

function sendReport(payload) {
    if (typeof window === 'undefined' || !window.fetch) return;

    // Deduplicate identical error messages within 30 seconds
    const key = `${payload.level}:${payload.message}`;
    if (reportedErrors.has(key)) return;
    reportedErrors.add(key);
    setTimeout(() => reportedErrors.delete(key), 30000);

    reportQueue.push(payload);

    if (flushTimeout) return;
    flushTimeout = setTimeout(() => {
        flushTimeout = null;
        const items = [...reportQueue];
        reportQueue = [];

        items.forEach((item) => {
            fetch('/api/logs/client', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(item),
                keepalive: true,
            }).catch(() => {
                // Silently ignore reporting errors to avoid error storms
            });
        });
    }, 500);
}

function formatConsole(level, tag, message, args) {
    const time = new Date().toLocaleTimeString();
    const prefix = `[FlowSync:${tag}] ${time}`;

    const styles = {
        debug: 'color: #94a3b8; font-weight: bold;',
        info: 'color: #3b82f6; font-weight: bold;',
        warn: 'color: #f59e0b; font-weight: bold;',
        error: 'color: #ef4444; font-weight: bold;',
    };

    const style = styles[level] || 'font-weight: bold;';

    if (level === 'error') {
        console.error(`%c${prefix}%c ${message}`, style, 'color: inherit;', ...args);
    } else if (level === 'warn') {
        console.warn(`%c${prefix}%c ${message}`, style, 'color: inherit;', ...args);
    } else if (level === 'info') {
        console.info(`%c${prefix}%c ${message}`, style, 'color: inherit;', ...args);
    } else {
        console.debug(`%c${prefix}%c ${message}`, style, 'color: inherit;', ...args);
    }
}

export const logger = {
    setLevel(level) {
        if (typeof LOG_LEVELS[level] === 'number') {
            currentLevel = LOG_LEVELS[level];
        }
    },

    debug(tag, message, ...args) {
        if (currentLevel <= LOG_LEVELS.debug) {
            formatConsole('debug', tag, message, args);
        }
    },

    info(tag, message, ...args) {
        if (currentLevel <= LOG_LEVELS.info) {
            formatConsole('info', tag, message, args);
        }
    },

    warn(tag, message, ...args) {
        if (currentLevel <= LOG_LEVELS.warn) {
            formatConsole('warn', tag, message, args);
        }

        // Forward warnings to backend
        sendReport({
            level: 'warn',
            message: `[${tag}] ${message}`,
            url: typeof window !== 'undefined' ? window.location.href : null,
            context: args.length > 0 && typeof args[0] === 'object' ? args[0] : { details: args },
        });
    },

    error(tag, message, ...args) {
        if (currentLevel <= LOG_LEVELS.error) {
            formatConsole('error', tag, message, args);
        }

        // Serialize Error objects if present
        let context = {};
        if (args.length > 0) {
            const firstArg = args[0];
            if (firstArg instanceof Error) {
                context = {
                    name: firstArg.name,
                    message: firstArg.message,
                    stack: firstArg.stack,
                    ...args.slice(1),
                };
            } else if (typeof firstArg === 'object' && firstArg !== null) {
                context = firstArg;
            } else {
                context = { details: args };
            }
        }

        // Forward errors to backend for aggregate debugging
        sendReport({
            level: 'error',
            message: `[${tag}] ${message}`,
            url: typeof window !== 'undefined' ? window.location.href : null,
            context,
        });
    },
};

export default logger;
