/**
 * Diagnostics logger for FlowSync MCP Server.
 * All output MUST be routed to stderr to ensure stdout remains 100% protocol-clean for JSON-RPC.
 */

export function redact(text) {
    if (typeof text !== 'string') {
        if (text && typeof text === 'object') {
            try {
                return JSON.parse(JSON.stringify(text, (key, value) => {
                    if (key.toLowerCase().includes('token') || key.toLowerCase().includes('authorization')) {
                        return '[REDACTED]';
                    }
                    return value;
                }));
            } catch {
                return '[Unserializable]';
            }
        }
        return text;
    }

    return text
        .replace(/Bearer\s+fst_[A-Za-z0-9_]+/gi, 'Bearer fst_[REDACTED]')
        .replace(/fst_\d+_[A-Za-z0-9]+/g, 'fst_[REDACTED]');
}

export function createLogger(debugEnabled = false) {
    function write(level, message, meta = null) {
        const timestamp = new Date().toISOString();
        const prefix = `[${timestamp}] [${level.toUpperCase()}]`;
        let line = `${prefix} ${redact(message)}`;
        if (meta) {
            line += ` ${JSON.stringify(redact(meta))}`;
        }
        process.stderr.write(line + '\n');
    }

    return {
        info: (msg, meta) => write('info', msg, meta),
        warn: (msg, meta) => write('warn', msg, meta),
        error: (msg, meta) => write('error', msg, meta),
        debug: (msg, meta) => {
            if (debugEnabled) {
                write('debug', msg, meta);
            }
        },
    };
}
