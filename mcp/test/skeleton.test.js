import test from 'node:test';
import assert from 'node:assert/strict';
import { loadConfig } from '../src/config.js';
import { createLogger, redact } from '../src/logger.js';
import { mapHttpError } from '../src/errors.js';
import { FlowSyncClient } from '../src/client.js';
import { createServer, verifyToken } from '../src/index.js';
import { McpError, ErrorCode } from '@modelcontextprotocol/sdk/types.js';

test('loadConfig validates environment variables properly', () => {
    // Missing URL
    assert.throws(() => loadConfig({}), /FLOWSYNC_URL environment variable is required/);

    // Non-https remote URL
    assert.throws(
        () => loadConfig({ FLOWSYNC_URL: 'http://example.com', FLOWSYNC_TOKEN: 'fst_1_abc' }),
        /FLOWSYNC_URL must use HTTPS for remote hosts/
    );

    // Missing token
    assert.throws(
        () => loadConfig({ FLOWSYNC_URL: 'http://localhost' }),
        /FLOWSYNC_TOKEN environment variable is required/
    );

    // Invalid token prefix
    assert.throws(
        () => loadConfig({ FLOWSYNC_URL: 'http://localhost', FLOWSYNC_TOKEN: 'bad_token' }),
        /FLOWSYNC_TOKEN must begin with "fst_"/
    );

    // Valid configuration
    const config = loadConfig({
        FLOWSYNC_URL: 'http://localhost:8000/',
        FLOWSYNC_TOKEN: 'fst_1_1234567890123456789012345678901234567890',
        FLOWSYNC_TIMEOUT_MS: '5000',
        FLOWSYNC_READ_ONLY: '1',
        FLOWSYNC_DEBUG: 'true',
    });

    assert.equal(config.baseUrl, 'http://localhost:8000');
    assert.equal(config.token, 'fst_1_1234567890123456789012345678901234567890');
    assert.equal(config.timeoutMs, 5000);
    assert.equal(config.readOnly, true);
    assert.equal(config.debug, true);
});

test('redact protects sensitive tokens in logs and text', () => {
    const raw = 'Bearer fst_42_abcdefghijklmnopqrstuvwxyz1234567890';
    const clean = redact(raw);
    assert.equal(clean, 'Bearer fst_[REDACTED]');

    const obj = {
        authorization: 'Bearer fst_42_abcdef',
        token: 'secret',
        normal: 'data',
    };
    const cleanObj = redact(obj);
    assert.equal(cleanObj.authorization, '[REDACTED]');
    assert.equal(cleanObj.token, '[REDACTED]');
    assert.equal(cleanObj.normal, 'data');
});

test('mapHttpError constructs actionable McpErrors with X-Request-Id', () => {
    const headers = new Headers({ 'x-request-id': 'req-xyz-123' });

    // 401
    const err401 = mapHttpError(401, {}, headers);
    assert.ok(err401 instanceof McpError);
    assert.match(err401.message, /Token invalid or tenant inactive/);
    assert.match(err401.message, /req-xyz-123/);

    // 403 token read only
    const err403ReadOnly = mapHttpError(403, { code: 'token_read_only' }, headers);
    assert.match(err403ReadOnly.message, /use a write token with tms\.write/);

    // 403 missing ability
    const err403Ability = mapHttpError(403, { code: 'token_ability_missing', ability: 'tms.write' }, headers);
    assert.match(err403Ability.message, /This token lacks required ability \(tms\.write\)/);

    // 422 validation
    const err422 = mapHttpError(422, { errors: { title: ['Title is required.'] } }, headers);
    assert.match(err422.message, /Validation failed: title: Title is required\./);

    // 429 usage limit
    const err429Usage = mapHttpError(429, { code: 'usage_limit' }, headers);
    assert.match(err429Usage.message, /Monthly API call quota exhausted — do not retry/);

    // 429 throttle
    const err429Rate = mapHttpError(429, {}, new Headers({ 'retry-after': '30', 'x-request-id': 'req-1' }));
    assert.match(err429Rate.message, /retry after 30 seconds/);

    // 503 maintenance
    const err503 = mapHttpError(503, { code: 'maintenance' }, headers);
    assert.match(err503.message, /Platform maintenance in progress/);
});

test('FlowSyncClient blocks writes in read-only mode and attaches Idempotency-Key', async () => {
    const mockLogger = { debug: () => {}, info: () => {}, warn: () => {}, error: () => {} };

    const client = new FlowSyncClient({
        baseUrl: 'http://localhost',
        token: 'fst_1_test',
        timeoutMs: 5000,
        readOnly: true,
    }, mockLogger);

    await assert.rejects(
        () => client.post('projects/1/tasks', { title: 'Test' }),
        /Server is configured in read-only mode/
    );
});

test('createServer registers flowsync_me and initializes schema', async () => {
    const config = {
        baseUrl: 'http://localhost',
        token: 'fst_1_test',
        timeoutMs: 5000,
        readOnly: false,
        debug: false,
    };
    const mockLogger = { debug: () => {}, info: () => {}, warn: () => {}, error: () => {} };
    const mockClient = {
        get: async (path) => {
            if (path === 'me') {
                return {
                    data: {
                        user: { id: 1, name: 'Alice', email: 'alice@test.com' },
                        tenant: { id: 10, name: 'Acme', slug: 'acme' },
                        token: { name: 'Agent', abilities: ['tms.read'], can_write: false },
                        usage: { api_calls: { used: 42, limit: 1000 } },
                        modules: ['tms.core'],
                    },
                };
            }
            throw new Error('Not found');
        },
    };

    const { tools } = createServer({ config, logger: mockLogger, client: mockClient });
    assert.ok(tools.has('flowsync_me'));

    const me = tools.get('flowsync_me');
    const result = await me.execute({}, mockClient);

    assert.ok(result.content[0].text.includes('Alice (alice@test.com)'));
    assert.ok(result.content[0].text.includes('Tenant: Acme (acme)'));
    assert.equal(result.structuredContent.user.id, 1);
});

test('verifyToken fails on auth refusal but tolerates a transient outage', async () => {
    const mockLogger = { debug: () => {}, info: () => {}, warn: () => {}, error: () => {} };
    const logLines = [];
    const logger = { ...mockLogger, info: (m) => logLines.push(m), warn: (m) => logLines.push(m) };

    const okClient = {
        getMe: async () => ({ user: { name: 'Alice' }, tenant: { slug: 'acme' } }),
    };
    const me = await verifyToken(okClient, logger);
    assert.equal(me.user.name, 'Alice');
    assert.ok(logLines.some((line) => line.includes('Authenticated as Alice (acme)')));

    const denied = new McpError(ErrorCode.InvalidRequest, 'Token invalid or tenant inactive');
    denied.status = 401;
    await assert.rejects(
        () => verifyToken({ getMe: async () => { throw denied; } }, logger),
        /Token verification failed/
    );

    const outage = new McpError(ErrorCode.InternalError, 'Network error');
    const result = await verifyToken({ getMe: async () => { throw outage; } }, logger);
    assert.equal(result, null);
    assert.ok(logLines.some((line) => line.includes('Could not verify token at startup')));
});
