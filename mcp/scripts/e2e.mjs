#!/usr/bin/env node

/**
 * End-to-End Verification Script for FlowSync MCP Server.
 *
 * Usage:
 *   FLOWSYNC_URL="http://localhost" FLOWSYNC_TOKEN="fst_1_..." node mcp/scripts/e2e.mjs
 */

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const serverPath = path.resolve(__dirname, '../src/index.js');

const url = process.env.FLOWSYNC_URL || 'http://localhost';
const token = process.env.FLOWSYNC_TOKEN;

if (!token) {
    console.error('Error: FLOWSYNC_TOKEN environment variable is required to run e2e.mjs');
    console.error('Usage: FLOWSYNC_URL="http://localhost" FLOWSYNC_TOKEN="fst_1_..." node mcp/scripts/e2e.mjs');
    process.exit(1);
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

    console.log('\n--- 2. Testing flowsync_me ---');
    const meRes = await client.callTool({ name: 'flowsync_me', arguments: {} });
    console.log(meRes.content[0].text);

    console.log('\n--- 3. Testing flowsync_list_workspaces ---');
    const wsRes = await client.callTool({ name: 'flowsync_list_workspaces', arguments: {} });
    console.log(wsRes.content[0].text);

    console.log('\n--- 4. Testing resources/list ---');
    const resList = await client.listResources();
    console.log(`Found ${resList.resources.length} resources:`);
    for (const r of resList.resources) {
        console.log(` - ${r.uri}: ${r.name}`);
    }

    console.log('\n--- 5. Testing prompts/list ---');
    const promptList = await client.listPrompts();
    console.log(`Found ${promptList.prompts.length} prompts:`);
    for (const p of promptList.prompts) {
        console.log(` - ${p.name}: ${p.description}`);
    }

    await client.close();
    console.log('\nAll E2E checks passed successfully!');
}

runE2e().catch((err) => {
    console.error('E2E Verification Failed:', err);
    process.exit(1);
});
