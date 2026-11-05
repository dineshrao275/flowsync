import crypto from 'node:crypto';
import { mapHttpError } from './errors.js';
import { McpError, ErrorCode } from '@modelcontextprotocol/sdk/types.js';
import { VERSION } from './config.js';

// A write whose outcome is unknown (timeout, dropped connection, 5xx, in-flight duplicate) may already have
// been applied. The next identical call reuses its Idempotency-Key for this long, so the server replays the
// first result instead of creating a duplicate.
const PENDING_WRITE_TTL_MS = 10 * 60 * 1000;

const WRITE_HINT = 'The write may already have been applied. Repeat the identical call to retry safely (the same request is de-duplicated), or read the record first to check.';

/** Same error with extra guidance appended (McpError builds its own message prefix, so rebuild it). */
function withHint(error, hint) {
    const plain = error.message.replace(/^MCP error -?\d+:\s*/, '');
    const next = new McpError(error.code, `${plain} ${hint}`);
    next.status = error.status;
    next.data = error.data;
    next.requestId = error.requestId;

    return next;
}

export class FlowSyncClient {
    constructor(config, logger) {
        this.baseUrl = config.baseUrl;
        this.token = config.token;
        this.timeoutMs = config.timeoutMs;
        this.readOnly = config.readOnly;
        this.logger = logger;

        // Pace calls: one in-flight request at a time, spaces starts so the token's
        // per-minute budget is respected. `maxRpm: 0` disables the spacing only.
        this.maxRpm = Number.isFinite(config.maxRpm) ? config.maxRpm : 60;
        this.minIntervalMs = this.maxRpm > 0 ? Math.ceil(60000 / this.maxRpm) : 0;
        this._lastRequestAt = 0;
        this._chain = Promise.resolve();

        // fingerprint (method|path|body) -> {key, at} for writes whose outcome is unknown.
        this._pendingWrites = new Map();

        // `flowsync_me` is cached briefly so tools can inspect the monthly budget
        // without spending a fresh API call every time.
        this._meCache = null;
        this._meCachedAt = 0;
        this._meCacheTtlMs = 60000;
    }

    /** Serialise every request so the agent never has more than one call in flight. */
    _enqueue(task) {
        const run = this._chain.then(task, task);
        // Keep the chain alive even when a call fails.
        this._chain = run.then(() => {}, () => {});
        return run;
    }

    async _waitForSlot() {
        if (!this.minIntervalMs) {
            return;
        }
        const waitMs = this._lastRequestAt + this.minIntervalMs - Date.now();
        if (waitMs > 0) {
            await new Promise((resolve) => setTimeout(resolve, waitMs));
        }
        this._lastRequestAt = Date.now();
    }

    /** Cached `/me` payload: identity, modules, abilities and monthly usage. */
    async getMe(force = false) {
        if (!force && this._meCache && Date.now() - this._meCachedAt < this._meCacheTtlMs) {
            return this._meCache;
        }
        const { data } = await this.get('me');
        this._meCache = data;
        this._meCachedAt = Date.now();
        return data;
    }

    /** Monthly API budget from `flowsync_me` (`{used, limit}`), or null when unknown. */
    async usageBudget(force = false) {
        const me = await this.getMe(force);
        return me?.usage?.api_calls ?? null;
    }

    request(method, path, options = {}) {
        return this._enqueue(() => this._request(method, path, options));
    }

    async _request(method, path, options = {}) {
        const isSafe = ['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase());

        if (!isSafe && this.readOnly) {
            throw new McpError(ErrorCode.InvalidRequest, 'Operation refused: Server is configured in read-only mode (FLOWSYNC_READ_ONLY=true).');
        }

        if (options.signal?.aborted) {
            throw new McpError(ErrorCode.RequestTimeout, 'Request cancelled.');
        }

        // Build URL with ?locale=en
        const urlObj = new URL(`${this.baseUrl}/api/v1/${path.replace(/^\/+/, '')}`);
        if (!urlObj.searchParams.has('locale')) {
            urlObj.searchParams.set('locale', 'en');
        }

        if (options.params) {
            for (const [k, v] of Object.entries(options.params)) {
                if (v !== undefined && v !== null && v !== '') {
                    urlObj.searchParams.set(k, String(v));
                }
            }
        }

        const headers = new Headers({
            'Authorization': `Bearer ${this.token}`,
            'Accept': 'application/json',
            'User-Agent': `flowsync-mcp/${VERSION}`,
            ...(options.headers || {}),
        });

        let body = null;
        if (options.body) {
            headers.set('Content-Type', 'application/json');
            body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
        }

        // Writes require an Idempotency-Key. A fresh key per call, except when the identical request just ended
        // with an unknown outcome: then the same key is reused so the retry cannot create a duplicate.
        const fingerprint = isSafe ? null : crypto.createHash('sha256').update(`${method}|${path}|${body ?? ''}`).digest('hex');
        if (!isSafe && !headers.has('Idempotency-Key')) {
            const pending = this._pendingWrites.get(fingerprint);
            headers.set('Idempotency-Key', pending && Date.now() - pending.at < PENDING_WRITE_TTL_MS ? pending.key : crypto.randomUUID());
        }
        const settle = (unknownOutcome) => {
            if (isSafe) return;
            if (unknownOutcome) {
                this._pendingWrites.set(fingerprint, { key: headers.get('Idempotency-Key'), at: Date.now() });
            } else {
                this._pendingWrites.delete(fingerprint);
            }
        };

        const maxAttempts = isSafe ? 3 : 1;
        let attempt = 0;

        while (attempt < maxAttempts) {
            attempt++;
            await this._waitForSlot();
            this.logger.debug(`HTTP ${method} ${urlObj.pathname}${urlObj.search}`, {
                attempt,
                idempotencyKey: headers.get('Idempotency-Key'),
            });

            if (options.signal?.aborted) {
                throw new McpError(ErrorCode.RequestTimeout, 'Request cancelled.');
            }

            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), this.timeoutMs);
            const onExternalAbort = () => controller.abort();
            options.signal?.addEventListener('abort', onExternalAbort, { once: true });
            const cleanup = () => {
                clearTimeout(timer);
                options.signal?.removeEventListener('abort', onExternalAbort);
            };

            try {
                const response = await fetch(urlObj.toString(), {
                    method,
                    headers,
                    body,
                    signal: controller.signal,
                });
                cleanup();

                const requestId = response.headers.get('x-request-id');
                const contentType = response.headers.get('content-type') || '';
                const isJson = contentType.includes('application/json');

                let responseData = {};
                if (isJson) {
                    try {
                        responseData = await response.json();
                    } catch {
                        responseData = {};
                    }
                } else {
                    const text = await response.text();
                    responseData = { raw: text };
                }

                if (response.ok || response.status === 202) {
                    settle(false);
                    this.logger.debug(`HTTP ${response.status} Success`, { requestId });
                    return {
                        status: response.status,
                        data: responseData,
                        headers: response.headers,
                    };
                }

                // Check for rate-limiting retry
                if (response.status === 429 && responseData?.code !== 'usage_limit' && isSafe && attempt < maxAttempts) {
                    const retryAfter = parseInt(response.headers.get('retry-after') || '2', 10);
                    const delayMs = Math.min(Math.max(retryAfter, 1) * 1000, 10000);
                    this.logger.warn(`Rate limited (429). Retrying after ${delayMs}ms (attempt ${attempt}/${maxAttempts})...`);
                    await new Promise((r) => setTimeout(r, delayMs));
                    continue;
                }

                // Maintenance is platform-wide: retrying here just burns the agent's
                // budget. Surface it immediately so the agent can back off.
                if (response.status === 503 && responseData?.code === 'maintenance') {
                    settle(false); // refused before it ran: nothing to de-duplicate
                    throw mapHttpError(response.status, responseData, response.headers);
                }

                // Temporary 5xx retry on safe read requests
                if (response.status >= 500 && isSafe && attempt < maxAttempts) {
                    const delayMs = attempt * 1000;
                    this.logger.warn(`Server error ${response.status}. Retrying after ${delayMs}ms (attempt ${attempt}/${maxAttempts})...`);
                    await new Promise((r) => setTimeout(r, delayMs));
                    continue;
                }

                const refusal = mapHttpError(response.status, responseData, response.headers);
                // 5xx and an in-flight duplicate (409) leave the outcome unknown; any other refusal is final.
                const unknown = response.status >= 500 || response.status === 409;
                settle(unknown);
                throw !isSafe && response.status >= 500 ? withHint(refusal, WRITE_HINT) : refusal;
            } catch (err) {
                cleanup();
                if (err.name === 'AbortError') {
                    settle(true);
                    // A caller-supplied cancellation is never retried, even for reads.
                    if (options.signal?.aborted) {
                        throw new McpError(ErrorCode.RequestTimeout, `Request cancelled.${isSafe ? '' : ` ${WRITE_HINT}`}`);
                    }
                    throw new McpError(ErrorCode.RequestTimeout, `Request timed out after ${this.timeoutMs}ms.${isSafe ? '' : ` ${WRITE_HINT}`}`);
                }
                if (err instanceof McpError) {
                    throw err;
                }
                if (isSafe && attempt < maxAttempts) {
                    await new Promise((r) => setTimeout(r, 1000 * attempt));
                    continue;
                }
                settle(true);
                throw new McpError(ErrorCode.InternalError, `Network error communicating with FlowSync: ${err.message}${isSafe ? '' : ` ${WRITE_HINT}`}`);
            }
        }
    }

    get(path, options = {}) {
        return this.request('GET', path, options);
    }

    post(path, body, options = {}) {
        return this.request('POST', path, { ...options, body });
    }

    put(path, body, options = {}) {
        return this.request('PUT', path, { ...options, body });
    }
}
