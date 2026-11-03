import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

test('server process strictly isolates stdout from logs and diagnostics', async () => {
    const indexPath = path.join(__dirname, '../src/index.js');
    const child = spawn(process.execPath, [indexPath], {
        env: {
            ...process.env,
            // Nothing listens on port 9: the test must not depend on (or hit) a developer's running app.
            FLOWSYNC_URL: 'http://127.0.0.1:9',
            FLOWSYNC_TOKEN: 'fst_1_test_token_12345678901234567890',
            FLOWSYNC_DEBUG: '1',
        },
        stdio: ['pipe', 'pipe', 'pipe'],
    });

    let stdoutData = '';
    let stderrData = '';

    child.stdout.on('data', (chunk) => {
        stdoutData += chunk.toString();
    });

    child.stderr.on('data', (chunk) => {
        stderrData += chunk.toString();
    });

    // Send a standard MCP ping / initialize request via stdin
    const initRequest = JSON.stringify({
        jsonrpc: '2.0',
        id: 1,
        method: 'initialize',
        params: {
            protocolVersion: '2024-11-05',
            capabilities: {},
            clientInfo: { name: 'test-client', version: '1.0.0' },
        },
    }) + '\n';

    child.stdin.write(initRequest);

    // Wait for response
    await new Promise((resolve) => setTimeout(resolve, 800));

    child.kill('SIGTERM');

    // Diagnostics must be on stderr, never stdout
    assert.ok(stderrData.includes('[INFO]') || stderrData.includes('running on stdio'));

    // Any output on stdout must be valid JSON-RPC
    const lines = stdoutData.trim().split('\n').filter(Boolean);

    // ... and the server must really have answered, or "every line is JSON-RPC" would hold for an empty stream.
    assert.ok(lines.length >= 1, 'the initialize request must be answered on stdout');
    assert.equal(JSON.parse(lines[0]).id, 1);
    for (const line of lines) {
        let parsed;
        assert.doesNotThrow(() => {
            parsed = JSON.parse(line);
        }, `Line on stdout is not valid JSON-RPC: "${line}"`);
        assert.equal(parsed.jsonrpc, '2.0');
    }
});
