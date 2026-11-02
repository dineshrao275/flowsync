import crypto from 'node:crypto';
import { mapHttpError } from './errors.js';
import { McpError, ErrorCode } from '@modelcontextprotocol/sdk/types.js';

export class FlowSyncClient {
    constructor(config, logger) {
        this.baseUrl = config.baseUrl;
        this.token = config.token;
        this.timeoutMs = config.timeoutMs;
        this.readOnly = config.readOnly;
        this.logger = logger;
    }

    async request(method, path, options = {}) {
        const isSafe = ['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase());

        if (!isSafe && this.readOnly) {
            throw new McpError(ErrorCode.InvalidRequest, 'Operation refused: Server is configured in read-only mode (FLOWSYNC_READ_ONLY=true).');
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
            'User-Agent': 'flowsync-mcp/1.0.0',
            ...(options.headers || {}),
        });

        let body = null;
        if (options.body) {
            headers.set('Content-Type', 'application/json');
            body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
        }

        // Writes require an Idempotency-Key
        if (!isSafe && !headers.has('Idempotency-Key')) {
            headers.set('Idempotency-Key', crypto.randomUUID());
        }

        const maxAttempts = isSafe ? 3 : 1;
        let attempt = 0;

        while (attempt < maxAttempts) {
            attempt++;
            this.logger.debug(`HTTP ${method} ${urlObj.pathname}${urlObj.search}`, {
                attempt,
                idempotencyKey: headers.get('Idempotency-Key'),
            });

            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), this.timeoutMs);

            try {
                const response = await fetch(urlObj.toString(), {
                    method,
                    headers,
                    body,
                    signal: controller.signal,
                });
                clearTimeout(timer);

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

                // Temporary 5xx retry on safe read requests
                if (response.status >= 500 && isSafe && attempt < maxAttempts) {
                    const delayMs = attempt * 1000;
                    this.logger.warn(`Server error ${response.status}. Retrying after ${delayMs}ms (attempt ${attempt}/${maxAttempts})...`);
                    await new Promise((r) => setTimeout(r, delayMs));
                    continue;
                }

                throw mapHttpError(response.status, responseData, response.headers);
            } catch (err) {
                clearTimeout(timer);
                if (err.name === 'AbortError') {
                    throw new McpError(ErrorCode.RequestTimeout, `Request timed out after ${this.timeoutMs}ms.`);
                }
                if (err instanceof McpError) {
                    throw err;
                }
                if (isSafe && attempt < maxAttempts) {
                    await new Promise((r) => setTimeout(r, 1000 * attempt));
                    continue;
                }
                throw new McpError(ErrorCode.InternalError, `Network error communicating with FlowSync: ${err.message}`);
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
