import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createServer } from '../src/index.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '../..');
const openApiPath = path.resolve(repoRoot, 'docs/openapi-v1.json');
const openApiContent = JSON.parse(fs.readFileSync(openApiPath, 'utf-8'));

// Extract all operationIds from openapi-v1.json
const openApiOperations = new Map();
for (const [pathStr, pathItem] of Object.entries(openApiContent.paths)) {
    for (const [method, operation] of Object.entries(pathItem)) {
        if (typeof operation === 'object' && operation.operationId) {
            openApiOperations.set(operation.operationId, {
                method: method.toUpperCase(),
                path: pathStr,
                summary: operation.summary,
            });
        }
    }
}

// Canonical tool to OpenAPI operationId mapping
const toolToOperationMap = {
    flowsync_me: ['getMe'],
    flowsync_list_workspaces: ['listWorkspaces'],
    flowsync_get_workspace: ['getWorkspace'],
    flowsync_list_projects: ['listProjects'],
    flowsync_get_project: ['getProject'],
    flowsync_list_tasks: ['listTasks'],
    flowsync_get_task: ['getTask', 'getTaskByKey'],
    flowsync_get_task_transitions: ['getTaskTransitions'],
    flowsync_list_task_activity: ['listTaskActivities'],
    flowsync_list_comments: ['listComments'],
    flowsync_list_worklogs: ['listWorkLogs'],
    flowsync_list_sprints: ['listSprints'],
    flowsync_search_tasks: ['searchTasks'],
    flowsync_create_task: ['createTask'],
    flowsync_update_task: ['updateTask'],
    flowsync_move_task: ['moveTask'],
    flowsync_add_comment: ['addComment'],
    flowsync_log_time: ['logTime'],
};

// Tool to ability group mapping
const toolToAbilityMap = {
    flowsync_me: null, // authenticated caller endpoint
    flowsync_list_workspaces: 'tms.read',
    flowsync_get_workspace: 'tms.read',
    flowsync_list_projects: 'tms.read',
    flowsync_get_project: 'tms.read',
    flowsync_list_tasks: 'tms.read',
    flowsync_get_task: 'tms.read',
    flowsync_get_task_transitions: 'tms.read',
    flowsync_list_task_activity: 'tms.read',
    flowsync_list_comments: 'tms.read',
    flowsync_list_worklogs: 'tms.read',
    flowsync_list_sprints: 'tms.read',
    flowsync_search_tasks: 'tms.read',
    flowsync_create_task: 'tms.write',
    flowsync_update_task: 'tms.write',
    flowsync_move_task: 'tms.write',
    flowsync_add_comment: 'tms.write',
    flowsync_log_time: 'tms.write',
};

// --- Source-derived contract: what the tools actually call --------------------------------------------------
// The two maps above are written by hand, so on their own they cannot notice a tool whose URL was changed or
// mistyped. These helpers read the calls straight out of src/tools/*.js.

const toolsDir = path.resolve(__dirname, '../src/tools');
const normalize = (p) => p.replace(/^\/+/, '').replace(/\$\{[^}]*\}|\{[^}]*\}/g, '{}');

/** { tool name -> [{ method, path }] } read from the tool sources. */
function extractToolCalls() {
    const calls = {};
    for (const file of fs.readdirSync(toolsDir).filter((f) => f.endsWith('.js'))) {
        const source = fs.readFileSync(path.join(toolsDir, file), 'utf-8');
        const parts = source.split(/(?=^\s{4}name:\s*'flowsync_)/m).slice(1);
        for (const part of parts) {
            const name = part.match(/name:\s*'(flowsync_[a-z_]+)'/)[1];
            const literals = [...part.matchAll(/path\s*=\s*([`'])((?:(?!\1).)*)\1/g)].map((m) => m[2]);
            const found = [];
            for (const m of part.matchAll(/client\.(get|post|put)\(\s*(?:([`'])((?:(?!\2).)*)\2|(path)\b)/g)) {
                const method = m[1].toUpperCase();
                if (m[4]) {
                    literals.forEach((lit) => found.push({ method, path: normalize(lit) }));
                } else {
                    found.push({ method, path: normalize(m[3]) });
                }
            }
            calls[name] = found;
        }
    }
    return calls;
}

const openApiOperationKeys = new Set(
    [...openApiOperations.values()].map((op) => `${op.method} ${normalize(op.path)}`),
);

test('Contract Guard: every call a tool makes is a documented operation (reads the tool source)', () => {
    const calls = extractToolCalls();
    const { tools } = createServer({
        config: { baseUrl: 'http://localhost', token: 'fst_1_test', timeoutMs: 1000, readOnly: false, debug: false },
        client: {},
    });

    for (const [toolName] of tools) {
        assert.ok(calls[toolName]?.length > 0, `${toolName}: no client.get/post/put call found in its source`);
        for (const call of calls[toolName]) {
            assert.ok(
                openApiOperationKeys.has(`${call.method} ${call.path}`),
                `${toolName} calls ${call.method} /${call.path}, which is not in docs/openapi-v1.json`,
            );
        }
    }
});

test('Contract Guard: the hand-written operationId map matches what the tools really call', () => {
    const calls = extractToolCalls();

    for (const [toolName, operationIds] of Object.entries(toolToOperationMap)) {
        const called = new Set((calls[toolName] ?? []).map((c) => `${c.method} ${c.path}`));
        const mapped = new Set(operationIds.map((id) => {
            const op = openApiOperations.get(id);
            return `${op.method} ${normalize(op.path)}`;
        }));

        assert.deepEqual([...called].sort(), [...mapped].sort(), `${toolName}: toolToOperationMap disagrees with the tool's real calls`);
    }
});

test('Contract Guard: Every MCP tool maps to an existing OpenAPI operationId', () => {
    const { tools } = createServer({
        config: { baseUrl: 'http://localhost', token: 'fst_1_test', timeoutMs: 1000, readOnly: false, debug: false },
        client: {},
    });

    for (const [toolName] of tools) {
        const expectedOperations = toolToOperationMap[toolName];
        assert.ok(expectedOperations, `Tool ${toolName} has no registered mapping in toolToOperationMap`);

        for (const opId of expectedOperations) {
            assert.ok(
                openApiOperations.has(opId),
                `Tool ${toolName} references operationId "${opId}" which is missing from docs/openapi-v1.json`
            );
        }
    }
});

test('Contract Guard: Every MCP tool ability is registered in config/api.php', () => {
    const configPath = path.resolve(__dirname, '../../config/api.php');
    const configContent = fs.readFileSync(configPath, 'utf-8');

    for (const [toolName, ability] of Object.entries(toolToAbilityMap)) {
        if (ability !== null) {
            assert.ok(
                configContent.includes(`'${ability}'`),
                `Tool ${toolName} requires ability "${ability}" but it is not defined in config/api.php`
            );
        }
    }
});

test('Contract Guard: Tool schemas define required descriptions and annotations', () => {
    const { tools } = createServer({
        config: { baseUrl: 'http://localhost', token: 'fst_1_test', timeoutMs: 1000, readOnly: false, debug: false },
        client: {},
    });

    for (const [toolName, tool] of tools) {
        assert.ok(tool.description && tool.description.length > 10, `${toolName} must have an informative description`);
        assert.ok(tool.inputSchema, `${toolName} must define an inputSchema`);
        assert.equal(tool.inputSchema.type, 'object', `${toolName} inputSchema must be type: object`);
        assert.ok(tool.annotations, `${toolName} must declare MCP annotations`);
        assert.equal(typeof tool.annotations.readOnlyHint, 'boolean', `${toolName} must declare boolean readOnlyHint`);
        assert.equal(typeof tool.annotations.destructiveHint, 'boolean', `${toolName} must declare boolean destructiveHint`);
        assert.equal(typeof tool.annotations.idempotentHint, 'boolean', `${toolName} must declare boolean idempotentHint`);
    }
});

test('Contract Guard: every MCP tool route exists in php artisan route:list', (t) => {
    const php = process.env.PHP_BIN || 'php';
    const result = spawnSync(php, ['artisan', 'route:list', '--json'], {
        cwd: repoRoot,
        encoding: 'utf8',
        timeout: 60000,
    });

    // Locally a checkout without PHP/vendor may skip this guard; in CI a skip would silently switch it off.
    const unavailable = (reason) => {
        if (process.env.CI) {
            assert.fail(`route:list guard cannot run in CI: ${reason}`);
        }
        t.skip(reason);
    };

    if (result.error?.code === 'ENOENT') {
        return unavailable('php binary not available; route:list guard runs where PHP is installed');
    }

    if (result.status !== 0) {
        // No vendor/, no .env, or no app key: meaningful only in a bootable checkout (the CI job installs both).
        return unavailable(`php artisan route:list unavailable: ${(result.stderr || '').trim().split('\n')[0] || 'unknown error'}`);
    }

    const stdout = (result.stdout || '').trim();
    const jsonStart = stdout.indexOf('[');
    assert.notEqual(jsonStart, -1, 'route:list --json did not return JSON');

    const routes = JSON.parse(stdout.slice(jsonStart));
    const available = new Set();
    for (const route of routes) {
        const methods = String(route.method || '')
            .split('|')
            .filter((method) => method && method !== 'HEAD');
        for (const method of methods) {
            available.add(`${method}|${route.uri}`);
        }
    }

    for (const [toolName, operationIds] of Object.entries(toolToOperationMap)) {
        for (const opId of operationIds) {
            const operation = openApiOperations.get(opId);
            assert.ok(operation, `${toolName} references unknown operationId ${opId}`);

            const uri = `api/v1${operation.path}`.replace(/\/+$/, '');
            const key = `${operation.method}|${uri}`;
            assert.ok(
                available.has(key),
                `Tool ${toolName}: route ${key} is missing from php artisan route:list`
            );
        }
    }
});

test('Contract Guard: each tool route is gated by a token_can middleware that includes the tool ability', (t) => {
    const php = process.env.PHP_BIN || 'php';
    const result = spawnSync(php, ['artisan', 'route:list', '--json'], { cwd: repoRoot, encoding: 'utf8', timeout: 60000 });
    if (result.error?.code === 'ENOENT' || result.status !== 0) {
        if (process.env.CI) assert.fail('route:list guard cannot run in CI');
        t.skip('php artisan route:list unavailable');
        return;
    }

    const stdout = (result.stdout || '').trim();
    const routes = JSON.parse(stdout.slice(stdout.indexOf('[')));
    const byKey = new Map();
    for (const route of routes) {
        for (const method of String(route.method || '').split('|').filter((m) => m && m !== 'HEAD')) {
            byKey.set(`${method} ${normalize(route.uri)}`, route);
        }
    }

    const calls = extractToolCalls();
    for (const [toolName, ability] of Object.entries(toolToAbilityMap)) {
        if (!ability) continue;
        for (const call of calls[toolName] ?? []) {
            const route = byKey.get(`${call.method} api/v1/${call.path}`);
            assert.ok(route, `${toolName}: no route for ${call.method} /api/v1/${call.path}`);
            const gated = (route.middleware || []).some(
                (m) => m.startsWith('token_can:') && m.slice('token_can:'.length).split(',').includes(ability),
            );
            assert.ok(gated, `${toolName}: route ${call.method} /api/v1/${call.path} is not gated by token_can:${ability}`);
        }
    }
});

test('HR data is view-only through the MCP: no HRMS tool, no HRMS call, and exactly the five task writes exist', () => {
    const { tools } = createServer({
        config: { baseUrl: 'http://localhost', token: 'fst_1_test', timeoutMs: 1000, readOnly: false, debug: false },
        client: {},
    });
    const calls = extractToolCalls();

    for (const [name, tool] of tools) {
        assert.doesNotMatch(name, /hrms|employee|payroll|leave|attendance|salary/i, `${name} must not be an HR tool`);
        for (const call of calls[name] ?? []) {
            assert.doesNotMatch(call.path, /hrms/i, `${name} calls an HRMS path: ${call.path}`);
            assert.match(call.path, /^(me|workspaces|projects|search)(\/|$)/, `${name} calls a path outside the TMS surface: ${call.path}`);
        }
        assert.doesNotMatch(tool.description, /hrms|payroll|salary/i, `${name} must not advertise HR data`);
    }

    // A new write tool must be a conscious decision: this list is the whole write surface.
    const writers = [...tools].filter(([, tool]) => tool.annotations.readOnlyHint === false).map(([name]) => name).sort();
    assert.deepEqual(writers, [
        'flowsync_add_comment',
        'flowsync_create_task',
        'flowsync_log_time',
        'flowsync_move_task',
        'flowsync_update_task',
    ]);
});

