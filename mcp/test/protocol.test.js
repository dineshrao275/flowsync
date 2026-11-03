import { VERSION } from '../src/config.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer } from '../src/index.js';

function createMockProtocolClient() {
    return {
        async get(endpoint) {
            if (endpoint === 'me') {
                return {
                    status: 200,
                    data: {
                        user: { id: 1, name: 'Alice Developer', email: 'alice@flowsync.test' },
                        tenant: { id: 10, name: 'Acme Corp', slug: 'acme' },
                        token: { name: 'Claude Code Agent', abilities: ['tms.read', 'tms.write'], can_write: true },
                        usage: { api_calls: { used: 42, limit: 1000 } },
                        modules: ['tms.core', 'time_tracking', 'sprints'],
                    },
                };
            }

            if (endpoint.startsWith('projects/10/tasks/key/WEB-42')) {
                return {
                    status: 200,
                    data: {
                        task: {
                            id: 1001,
                            key: 'WEB-42',
                            title: 'Implement OAuth',
                            description: 'Add OAuth 2.0 PKCE flow',
                            status: { name: 'To Do' },
                            priority: { name: 'High' },
                            assignee: { name: 'Alice' },
                        },
                    },
                };
            }

            throw new Error(`Unhandled mock endpoint: ${endpoint}`);
        },

        async post(endpoint, body) {
            if (endpoint === 'projects/10/tasks') {
                return {
                    status: 201,
                    data: {
                        task: {
                            id: 1002,
                            key: 'WEB-43',
                            title: body.title,
                            status: { name: 'To Do' },
                        },
                    },
                };
            }
            throw new Error(`Unhandled mock POST endpoint: ${endpoint}`);
        },
    };
}

const mockConfig = {
    baseUrl: 'http://localhost',
    token: 'fst_1_mock1234567890123456789012345678901234567890',
    timeoutMs: 1000,
    readOnly: false,
    debug: false,
};

test('Protocol Conformance: Client handshake, capabilities, ping, and lifecycle over InMemoryTransport', async () => {
    const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
    const mockApiClient = createMockProtocolClient();

    const client = new Client(
        { name: 'claude-code-test-harness', version: '1.0.0' },
        { capabilities: {} }
    );

    const { server } = createServer({
        config: mockConfig,
        client: mockApiClient,
    });

    // 1. Connect and initialize handshake
    await Promise.all([
        client.connect(clientTransport),
        server.connect(serverTransport),
    ]);

    // 2. Server identity & instructions
    const serverVersion = client.getServerVersion();
    assert.equal(serverVersion.name, 'flowsync-app');
    assert.equal(serverVersion.version, VERSION);

    const instructions = client.getInstructions();
    assert.match(instructions, /FlowSync Task Management/);
    assert.match(instructions, /untrusted data/);

    // 3. Ping
    await client.ping();

    // 4. Tools list
    const toolsResult = await client.listTools();
    assert.equal(toolsResult.tools.length, 18);
    const toolNames = toolsResult.tools.map((t) => t.name);
    assert.ok(toolNames.includes('flowsync_me'));
    assert.ok(toolNames.includes('flowsync_create_task'));

    // 5. Tool call (flowsync_me)
    const meResult = await client.callTool({
        name: 'flowsync_me',
        arguments: {},
    });
    assert.match(meResult.content[0].text, /Alice Developer/);
    assert.equal(meResult.structuredContent.user.id, 1);
    assert.equal(meResult.structuredContent.tenant.slug, 'acme');

    // 6. Tool call (flowsync_create_task)
    const createResult = await client.callTool({
        name: 'flowsync_create_task',
        arguments: {
            project_id: 10,
            title: 'Protocol test task',
        },
    });
    assert.match(createResult.content[0].text, /Task created successfully: \[WEB-43\]/);
    assert.equal(createResult.structuredContent.task.id, 1002);

    // 7. Resources list
    const resourcesResult = await client.listResources();
    assert.ok(resourcesResult.resources.length >= 2);
    const uris = resourcesResult.resources.map((r) => r.uri);
    assert.ok(uris.includes('flowsync://context/architecture'));
    assert.ok(uris.includes('flowsync://context/conventions'));

    // 8. Resource read
    const readResult = await client.readResource({
        uri: 'flowsync://context/architecture',
    });
    assert.equal(readResult.contents[0].mimeType, 'text/markdown');
    assert.match(readResult.contents[0].text, /Multi-Tenant Architecture/);

    // 9. Prompts list
    const promptsResult = await client.listPrompts();
    assert.equal(promptsResult.prompts.length, 4);
    const promptNames = promptsResult.prompts.map((p) => p.name);
    assert.ok(promptNames.includes('plan_task'));
    assert.ok(promptNames.includes('implement_task'));

    // 10. Prompt get
    const promptResult = await client.getPrompt({
        name: 'plan_task',
        arguments: {
            project_id: '10',
            task_key: 'WEB-42',
        },
    });
    assert.match(promptResult.description, /Plan implementation for task WEB-42/);
    assert.match(promptResult.messages[0].content.text, /Implement OAuth/);

    // 11. Clean shutdown
    await client.close();
    await server.close();
});
