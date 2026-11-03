import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from '../src/index.js';
import {
    ListPromptsRequestSchema,
    GetPromptRequestSchema,
    McpError,
} from '@modelcontextprotocol/sdk/types.js';

function createMockPromptClient() {
    return {
        async get(endpoint) {
            if (endpoint.startsWith('projects/10/tasks/key/WEB-42')) {
                return {
                    status: 200,
                    data: {
                        task: {
                            id: 1001,
                            key: 'WEB-42',
                            title: 'Implement OAuth',
                            description: 'Add OAuth 2.0 flow',
                            status: { name: 'To Do' },
                            priority: { name: 'High' },
                            assignee: { name: 'Alice' },
                        },
                    },
                };
            }

            if (endpoint === 'projects/10/tasks/1001/comments') {
                return {
                    status: 200,
                    data: {
                        comments: [
                            { user: { name: 'Bob' }, comment: 'Please consider PKCE' },
                        ],
                    },
                };
            }

            if (endpoint.startsWith('projects/10/tasks')) {
                return {
                    status: 200,
                    data: {
                        tasks: [
                            { key: 'WEB-42', title: 'Implement OAuth', status: { name: 'To Do' }, priority: { name: 'High' } },
                        ],
                    },
                };
            }

            if (endpoint === 'workspaces') {
                return {
                    status: 200,
                    data: {
                        workspaces: [{ name: 'Acme Core' }],
                    },
                };
            }

            throw new Error(`Unhandled prompt mock endpoint: ${endpoint}`);
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

test('All Phase 5 Prompts are registered and retrievable via ListPrompts', async () => {
    const mockClient = createMockPromptClient();
    const { server } = createServer({ config: mockConfig, client: mockClient });

    const handler = server._requestHandlers.get('prompts/list');
    const promptsResponse = await handler({ method: 'prompts/list' });
    const promptNames = promptsResponse.prompts.map((p) => p.name);

    assert.ok(promptNames.includes('plan_task'));
    assert.ok(promptNames.includes('implement_task'));
    assert.ok(promptNames.includes('review_task'));
    assert.ok(promptNames.includes('daily_digest'));
});

test('Prompts execute and return formatted messages', async () => {
    const mockClient = createMockPromptClient();
    const { server } = createServer({ config: mockConfig, client: mockClient });
    const rawGetHandler = server._requestHandlers.get('prompts/get');
    const getHandler = (payload) => rawGetHandler({ method: 'prompts/get', ...payload });

    // 1. plan_task
    const planRes = await getHandler({
        params: {
            name: 'plan_task',
            arguments: { project_id: '10', task_key: 'WEB-42' },
        },
    });
    assert.match(planRes.description, /Plan implementation for task WEB-42/);
    assert.match(planRes.messages[0].content.text, /Implement OAuth/);
    assert.match(planRes.messages[0].content.text, /flowsync:\/\/context\/conventions/);

    // 2. implement_task
    const implRes = await getHandler({
        params: {
            name: 'implement_task',
            arguments: { project_id: '10', task_key: 'WEB-42' },
        },
    });
    assert.match(implRes.description, /Implement task WEB-42/);
    assert.match(implRes.messages[0].content.text, /Implementation guidelines/);

    // 3. review_task (verifies comments included)
    const revRes = await getHandler({
        params: {
            name: 'review_task',
            arguments: { project_id: '10', task_key: 'WEB-42' },
        },
    });
    assert.match(revRes.description, /Review task WEB-42/);
    assert.match(revRes.messages[0].content.text, /Please consider PKCE/);

    // 4. daily_digest
    const digestRes = await getHandler({
        params: {
            name: 'daily_digest',
            arguments: { project_id: '10' },
        },
    });
    assert.match(digestRes.description, /Daily activity digest/);
    assert.match(digestRes.messages[0].content.text, /Implement OAuth/);

    // 5. Unknown prompt throws MethodNotFound
    await assert.rejects(
        () => getHandler({ params: { name: 'unknown_prompt', arguments: {} } }),
        (err) => err instanceof McpError && /Unknown prompt/.test(err.message)
    );
});
