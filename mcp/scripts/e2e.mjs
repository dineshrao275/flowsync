#!/usr/bin/env node

/**
 * End-to-end verification script for the FlowSync MCP server.
 *
 * Read-only by default (initialize, tools/resources/prompts, me, workspaces).
 * Set FLOWSYNC_E2E_WRITE=1 (and FLOWSYNC_E2E_PROJECT_ID) to exercise the full
 * write lifecycle: create -> move -> comment -> log time. That creates real
 * data in the target tenant, so only run it against a staging/test tenant.
 *
 * Usage:
 *   FLOWSYNC_URL="http://localhost" FLOWSYNC_TOKEN="fst_1_..." node mcp/scripts/e2e.mjs
 *   FLOWSYNC_E2E_WRITE=1 FLOWSYNC_E2E_PROJECT_ID=10 \
 *     FLOWSYNC_URL="http://localhost" FLOWSYNC_TOKEN="fst_1_..." node mcp/scripts/e2e.mjs
 */

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const serverPath = path.resolve(__dirname, '../src/index.js');

const url = process.env.FLOWSYNC_URL || 'http://localhost';
const token = process.env.FLOWSYNC_TOKEN;
const writeEnabled = ['1', 'true'].includes(String(process.env.FLOWSYNC_E2E_WRITE || '').toLowerCase());
const projectId = parseInt(process.env.FLOWSYNC_E2E_PROJECT_ID || '', 10);

if (!token) {
    console.error('Error: FLOWSYNC_TOKEN environment variable is required to run e2e.mjs');
    console.error('Usage: FLOWSYNC_URL="http://localhost" FLOWSYNC_TOKEN="fst_1_..." node mcp/scripts/e2e.mjs');
    process.exit(1);
}

function printResult(label, result) {
    const text = result?.content?.find((c) => c.type === 'text')?.text;
    console.log(`${label}\n${text ?? JSON.stringify(result.structuredContent ?? result)}`);
}

async function runE2e() {
    console.log(`Connecting to FlowSync at ${url}...`);

    const transport = new StdioClientTransport({
        command: 'node',
        args: [serverPath],
        env: {
            ...process.env,
            FLOWSYNC_URL: url,
            FLOWSYNC_TOKEN: token,
        },
    });

    const client = new Client(
        { name: 'flowsync-e2e-tester', version: '1.0.0' },
        { capabilities: {} }
    );

    await client.connect(transport);
    console.log('Connected! Negotiated version:', client.getServerVersion());

    console.log('\n--- 1. Testing ping ---');
    await client.ping();
    console.log('Ping OK');

    console.log('\n--- 2. Testing tools/list ---');
    const { tools } = await client.listTools();
    const names = tools.map((tool) => tool.name);
    console.log(`Found ${tools.length} tools: ${names.join(', ')}`);
    if (!names.includes('flowsync_me') || !names.includes('flowsync_create_task')) {
        throw new Error('tools/list is missing expected read/write tools');
    }

    console.log('\n--- 3. Testing flowsync_me ---');
    printResult('me:', await client.callTool({ name: 'flowsync_me', arguments: {} }));

    console.log('\n--- 4. Testing flowsync_list_workspaces ---');
    printResult('workspaces:', await client.callTool({ name: 'flowsync_list_workspaces', arguments: {} }));

    console.log('\n--- 5. Testing resources/list ---');
    const resList = await client.listResources();
    console.log(`Found ${resList.resources.length} resources:`);
    for (const r of resList.resources) {
        console.log(` - ${r.uri}: ${r.name}`);
    }

    console.log('\n--- 6. Testing prompts/list ---');
    const promptList = await client.listPrompts();
    console.log(`Found ${promptList.prompts.length} prompts:`);
    for (const p of promptList.prompts) {
        console.log(` - ${p.name}: ${p.description}`);
    }

    if (!writeEnabled) {
        console.log('\nRead-only E2E complete. Set FLOWSYNC_E2E_WRITE=1 to exercise the write lifecycle.');
        await client.close();
        console.log('\nAll E2E checks passed successfully!');
        return;
    }

    if (!Number.isInteger(projectId) || projectId <= 0) {
        throw new Error('FLOWSYNC_E2E_WRITE=1 requires a positive FLOWSYNC_E2E_PROJECT_ID');
    }

    console.log(`\n--- 7. Write lifecycle on project ${projectId} ---`);
    const projectRes = await client.callTool({ name: 'flowsync_get_project', arguments: { project_id: projectId } });
    const project = projectRes.structuredContent?.project ?? {};
    const statuses = project.statuses ?? [];
    if (statuses.length === 0) {
        throw new Error('Project has no workflow statuses to move through');
    }

    const created = await client.callTool({
        name: 'flowsync_create_task',
        arguments: {
            project_id: projectId,
            title: `MCP E2E ${new Date().toISOString()}`,
            description: 'Created by mcp/scripts/e2e.mjs against a staging tenant.',
        },
    });
    const task = created.structuredContent?.task ?? {};
    if (!task.id) {
        throw new Error('flowsync_create_task did not return a task id');
    }
    printResult(`Task created (${task.key}):`, created);

    printResult('Task detail:', await client.callTool({
        name: 'flowsync_get_task',
        arguments: { project_id: projectId, task_id: task.id },
    }));

    const currentStatusId = task.status_id ?? task.status?.id;
    const targetStatus = statuses.find((status) => status.id !== currentStatusId) ?? statuses[0];

    printResult('Transitions:', await client.callTool({
        name: 'flowsync_get_task_transitions',
        arguments: { project_id: projectId, task_id: task.id },
    }));

    const moved = await client.callTool({
        name: 'flowsync_move_task',
        arguments: { project_id: projectId, task_id: task.id, status_id: targetStatus.id },
    });
    printResult('Move result:', moved);
    if (moved.structuredContent?.pending_approval) {
        console.log('Move was approval-gated (202) — task is unchanged, as expected.');
    }

    printResult('Comment:', await client.callTool({
        name: 'flowsync_add_comment',
        arguments: { project_id: projectId, task_id: task.id, comment: 'MCP E2E comment.' },
    }));

    printResult('Work log:', await client.callTool({
        name: 'flowsync_log_time',
        arguments: {
            project_id: projectId,
            task_id: task.id,
            description: 'MCP E2E work log',
            duration_minutes: 15,
        },
    }));

    await client.close();
    console.log('\nAll E2E checks passed successfully!');
}

runE2e().catch((err) => {
    console.error('E2E Verification Failed:', err);
    process.exit(1);
});
