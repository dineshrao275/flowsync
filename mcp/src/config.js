/**
 * Configuration loader for FlowSync MCP Server.
 * Validates environment variables and enforces safe defaults.
 */

export function loadConfig(env = process.env) {
    const rawUrl = env.FLOWSYNC_URL?.trim();
    if (!rawUrl) {
        throw new Error('FLOWSYNC_URL environment variable is required (e.g. http://localhost or https://app.flowsync.com)');
    }

    let url;
    try {
        url = new URL(rawUrl);
    } catch {
        throw new Error(`FLOWSYNC_URL is not a valid URL: "${rawUrl}"`);
    }

    const isLocalhost = url.hostname === 'localhost' || url.hostname === '127.0.0.1' || url.hostname === '::1';
    if (!isLocalhost && url.protocol !== 'https:') {
        throw new Error(`FLOWSYNC_URL must use HTTPS for remote hosts (received: "${rawUrl}")`);
    }

    const token = env.FLOWSYNC_TOKEN?.trim();
    if (!token) {
        throw new Error('FLOWSYNC_TOKEN environment variable is required (Personal API token formatted as fst_<tenantId>_<random>)');
    }

    if (!token.startsWith('fst_')) {
        throw new Error(`Invalid token format: FLOWSYNC_TOKEN must begin with "fst_" (received: "${token.substring(0, 8)}...")`);
    }

    const timeoutMs = parseInt(env.FLOWSYNC_TIMEOUT_MS ?? '15000', 10);
    const readOnly = env.FLOWSYNC_READ_ONLY === '1' || env.FLOWSYNC_READ_ONLY === 'true';
    const debug = env.FLOWSYNC_DEBUG === '1' || env.FLOWSYNC_DEBUG === 'true';
    const maxRpm = parseInt(env.FLOWSYNC_MAX_RPM ?? '60', 10);

    return {
        baseUrl: rawUrl.replace(/\/+$/, ''),
        token,
        timeoutMs: Number.isFinite(timeoutMs) && timeoutMs > 0 ? timeoutMs : 15000,
        readOnly,
        debug,
        // Highest requests-per-minute the client spaces calls for. 0 disables pacing
        // (the server still enforces the token's own limit).
        maxRpm: Number.isFinite(maxRpm) && maxRpm >= 0 ? maxRpm : 60,
    };
}
