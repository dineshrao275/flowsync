import test from 'node:test';
import assert from 'node:assert/strict';
import { FlowSyncClient } from '../src/client.js';
import { McpError } from '@modelcontextprotocol/sdk/types.js';

const logger = { debug: () => {}, info: () => {}, warn: () => {}, error: () => {} };

function makeClient(overrides = {}) {
    return new FlowSyncClient({
        baseUrl: 'http://localhost',
        token: 'fst_1_test_token_12345678901234567890',
        timeoutMs: 2000,
        readOnly: false,
        maxRpm: 0,
        ...overrides,
    }, logger);
}

function jsonResponse(status, body = {}, headers = {}) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'content-type': 'application/json', ...headers },
    });
}

async function withFetch(mock, fn) {
    const original = globalThis.fetch;
    globalThis.fetch = mock;
    try {
        return await fn();
    } finally {
        globalThis.fetch = original;
    }
}

test('maps 401, 404, 409 and 422 responses to actionable McpErrors', async () => {
    const cases = [
        [401, {}, /Token invalid or tenant inactive/],
        [404, {}, /Not found\./],
        [409, {}, /still in-flight/],
        [422, { errors: { title: ['Title is required.'] } }, /Validation failed: title: Title is required\./],
    ];

    for (const [status, body, expected] of cases) {
        const calls = [];
        await withFetch(async () => {
            calls.push(1);
            return jsonResponse(status, body);
        }, async () => {
            await assert.rejects(
                () => makeClient().get('me'),
                (err) => err instanceof McpError && expected.test(err.message)
            );
        });
        assert.equal(calls.length, 1, `status ${status} must not be retried`);
    }
});

test('429 usage_limit is not retried; 429 rate limit is retried then succeeds', async () => {
    let usageCalls = 0;
    await withFetch(async () => {
        usageCalls++;
        return jsonResponse(429, { code: 'usage_limit' }, { 'retry-after': '1' });
    }, async () => {
        await assert.rejects(
            () => makeClient().get('projects'),
            /Monthly API call quota exhausted/
        );
    });
    assert.equal(usageCalls, 1, 'usage_limit must never be retried');

    let rateCalls = 0;
    await withFetch(async () => {
        rateCalls++;
        return rateCalls === 1
            ? jsonResponse(429, {}, { 'retry-after': '1' })
            : jsonResponse(200, { workspaces: [] });
    }, async () => {
        const result = await makeClient().get('workspaces');
        assert.equal(result.status, 200);
    });
    assert.equal(rateCalls, 2, 'rate limit should be retried once');
});

test('5xx is retried for reads but not for writes', async () => {
    let readCalls = 0;
    await withFetch(async () => {
        readCalls++;
        return readCalls === 1
            ? jsonResponse(500, { message: 'boom' })
            : jsonResponse(200, { tasks: [] });
    }, async () => {
        const result = await makeClient().get('projects/1/tasks');
        assert.equal(result.status, 200);
    });
    assert.equal(readCalls, 2);

    let writeCalls = 0;
    await withFetch(async () => {
        writeCalls++;
        return jsonResponse(500, { message: 'boom' });
    }, async () => {
        await assert.rejects(
            () => makeClient().post('projects/1/tasks', { title: 'x' }),
            /Temporary server error \(500\)/
        );
    });
    assert.equal(writeCalls, 1, 'writes must not be retried automatically');
});

test('every write carries an Idempotency-Key; reads do not', async () => {
    let writeKey = null;
    await withFetch(async (_url, init) => {
        writeKey = new Headers(init.headers).get('idempotency-key');
        return jsonResponse(201, { task: { id: 1 } });
    }, async () => {
        await makeClient().post('projects/1/tasks', { title: 'x' });
    });
    assert.match(writeKey || '', /^[0-9a-f-]{36}$/i);

    let readKey = 'unset';
    await withFetch(async (_url, init) => {
        readKey = new Headers(init.headers).get('idempotency-key');
        return jsonResponse(200, {});
    }, async () => {
        await makeClient().get('me');
    });
    assert.equal(readKey, null);
});

test('sends locale=en and the MCP User-Agent marker', async () => {
    let url = null;
    let headers = null;
    await withFetch(async (u, init) => {
        url = new URL(u.toString());
        headers = new Headers(init.headers);
        return jsonResponse(200, {});
    }, async () => {
        await makeClient().get('projects');
    });

    assert.equal(url.searchParams.get('locale'), 'en');
    assert.match(headers.get('user-agent'), /^flowsync-mcp\/1\.0\.0$/);
});

test('serialises concurrent calls so at most one request is in flight', async () => {
    let active = 0;
    let maxActive = 0;

    await withFetch(async () => {
        active++;
        maxActive = Math.max(maxActive, active);
        await new Promise((resolve) => setTimeout(resolve, 20));
        active--;
        return jsonResponse(200, {});
    }, async () => {
        const client = makeClient();
        await Promise.all([
            client.get('workspaces'),
            client.get('projects'),
            client.get('me'),
        ]);
    });

    assert.equal(maxActive, 1);
});

test('a caller cancellation aborts the request and is never retried', async () => {
    let calls = 0;
    const controller = new AbortController();

    await withFetch((_url, init) => new Promise((_resolve, reject) => {
        calls++;
        const err = new DOMException('Aborted', 'AbortError');
        if (init.signal?.aborted) {
            reject(err);
            return;
        }
        init.signal?.addEventListener('abort', () => reject(err), { once: true });
        setTimeout(() => controller.abort(), 10);
    }), async () => {
        await assert.rejects(
            () => makeClient().get('projects', { signal: controller.signal }),
            /Request cancelled\./
        );
    });

    assert.equal(calls, 1);
});

test('getMe caches the budget payload briefly', async () => {
    let calls = 0;
    await withFetch(async () => {
        calls++;
        return jsonResponse(200, {
            user: { id: 1, name: 'Alice' },
            usage: { api_calls: { used: 10, limit: 100 } },
        });
    }, async () => {
        const client = makeClient();
        assert.deepEqual(await client.usageBudget(), { used: 10, limit: 100 });
        assert.deepEqual(await client.usageBudget(), { used: 10, limit: 100 });
    });

    assert.equal(calls, 1, 'the second budget read should come from cache');
});

test('403 responses name the missing ability, module/product and onboarding state', async () => {
    const cases = [
        [{ code: 'token_read_only' }, {}, /write token with tms\.write/],
        [{ code: 'token_ability_missing', ability: 'tms.write' }, {}, /lacks required ability \(tms\.write\)/],
        [{}, { 'x-module-denied': 'api' }, /does not include access to the 'api' capability/],
        [{}, { 'x-product-denied': 'hrms' }, /does not include access to the 'hrms' capability/],
        [{}, { 'x-onboarding-redirect': '/app/onboarding' }, /Finish tenant onboarding/],
    ];

    for (const [body, headers, expected] of cases) {
        await withFetch(
            async () => jsonResponse(403, body, headers),
            async () => {
                await assert.rejects(
                    () => makeClient().get('me'),
                    (err) => err instanceof McpError && err.status === 403 && expected.test(err.message)
                );
            }
        );
    }
});

test('503 maintenance is surfaced immediately, not retried', async () => {
    let calls = 0;
    await withFetch(async () => {
        calls++;
        return jsonResponse(503, { code: 'maintenance' }, { 'retry-after': '60' });
    }, async () => {
        await assert.rejects(
            () => makeClient().get('projects'),
            (err) => err instanceof McpError && err.status === 503 && /maintenance in progress/.test(err.message)
        );
    });
    assert.equal(calls, 1, 'maintenance must not be retried');
});
