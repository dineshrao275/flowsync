import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from '../src/index.js';
import {
    ListToolsRequestSchema,
    CallToolRequestSchema,
    ListResourcesRequestSchema,
    ReadResourceRequestSchema,
    McpError,
} from '@modelcontextprotocol/sdk/types.js';

function createMockClient() {
    const calls = [];
    return {
        calls,
        async get(endpoint, options = {}) {
            const params = options.params || {};
            calls.push({ method: 'GET', endpoint, options, params });

            if (endpoint === 'workspaces') {
                return {
                    status: 200,
                    data: {
                        workspaces: [
                            { id: 1, name: 'Acme Core', slug: 'acme-core', description: 'Main workspace' },
                        ],
                    },
                };
            }
            if (endpoint === 'workspaces/1') {
                return {
                    status: 200,
                    data: {
                        workspace: { id: 1, name: 'Acme Core', slug: 'acme-core', description: 'Main workspace' },
                    },
                };
            }
            if (endpoint === 'projects') {
                return {
                    status: 200,
                    data: {
                        projects: [
                            { id: 10, name: 'Web App', key: 'WEB', workspace_id: 1, description: 'Frontend client' },
                        ],
                    },
                };
            }
            if (endpoint === 'projects/10') {
                return {
                    status: 200,
                    data: {
                        project: {
                            id: 10,
                            name: 'Web App',
                            key: 'WEB',
                            statuses: [{ id: 101, name: 'To Do', category: 'todo' }, { id: 102, name: 'Done', category: 'done' }],
                            members: [{ id: 5, name: 'Alice Developer', role: 'developer' }],
                        },
                    },
                };
            }
            if (endpoint === 'projects/10/tasks') {
                return {
                    status: 200,
                    data: {
                        tasks: [
                            { id: 1001, key: 'WEB-1', title: 'Setup auth', status: { name: 'To Do' }, priority: { name: 'High' } },
                        ],
                        total: 1,
                    },
                };
            }
            if (endpoint === 'projects/10/tasks/1001') {
                return {
                    status: 200,
                    data: {
                        task: {
                            id: 1001,
                            key: 'WEB-1',
                            title: 'Setup auth',
                            description: 'Implement JWT login',
                            status: { name: 'To Do' },
                            priority: { name: 'High' },
                            assignee: { name: 'Alice' },
                        },
                    },
                };
            }
            if (endpoint === 'projects/10/tasks/key/WEB-1') {
                return {
                    status: 200,
                    data: {
                        task: {
                            id: 1001,
                            key: 'WEB-1',
                            title: 'Setup auth',
                            description: 'Implement JWT login',
                            status: { name: 'To Do' },
                            priority: { name: 'High' },
                            assignee: { name: 'Alice' },
                        },
                    },
                };
            }
            if (endpoint === 'projects/10/tasks/1001/transitions') {
                return {
                    status: 200,
                    data: {
                        transitions: [
                            { status_id: 102, name: 'Done', allowed: true, blocked_reason: null },
                        ],
                    },
                };
            }
            if (endpoint === 'projects/10/tasks/1001/activities') {
                return {
                    status: 200,
                    data: {
                        activities: [
                            { id: 1, action: 'created task', actor: { name: 'Alice' }, created_at: '2026-11-01' },
                        ],
                    },
                };
            }
            if (endpoint === 'projects/10/tasks/1001/comments') {
                return {
                    status: 200,
                    data: {
                        comments: [
                            { id: 50, comment: 'Working on it', user: { name: 'Alice' }, created_at: '2026-11-02' },
                        ],
                    },
                };
            }
            if (endpoint === 'projects/10/tasks/1001/work-logs') {
                return {
                    status: 200,
                    data: {
                        work_logs: [
                            { id: 99, duration_seconds: 3600, description: 'Coding', user: { name: 'Alice' }, started_at: '2026-11-02' },
                        ],
                    },
                };
            }
            if (endpoint === 'projects/10/sprints') {
                return {
                    status: 200,
                    data: {
                        sprints: [
                            { id: 7, name: 'Sprint 1', status: 'active', start_date: '2026-11-01', end_date: '2026-11-15' },
                        ],
                    },
                };
            }
            if (endpoint === 'search/tasks') {
                return {
                    status: 200,
                    data: {
                        tasks: [
                            { id: 1001, key: 'WEB-1', title: 'Setup auth', status: { name: 'To Do' } },
                        ],
                    },
                };
            }

            throw new Error(`Unhandled mock endpoint: ${endpoint}`);
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

test('All Phase 3 Read Tools are registered and retrievable via ListTools', async () => {
    const mockClient = createMockClient();
    const { server } = createServer({ config: mockConfig, client: mockClient });

    const handler = server._requestHandlers.get('tools/list');
    const toolsResponse = await handler({ method: 'tools/list' });
    const toolNames = toolsResponse.tools.map((t) => t.name);

    const expectedTools = [
        'flowsync_me',
        'flowsync_list_workspaces',
        'flowsync_get_workspace',
        'flowsync_list_projects',
        'flowsync_get_project',
        'flowsync_list_tasks',
        'flowsync_get_task',
        'flowsync_get_task_transitions',
        'flowsync_list_task_activity',
        'flowsync_list_comments',
        'flowsync_list_worklogs',
        'flowsync_list_sprints',
        'flowsync_search_tasks',
    ];

    for (const expected of expectedTools) {
        assert.ok(toolNames.includes(expected), `Missing tool: ${expected}`);
    }
});

test('Read tools execute correctly and format both text and structured content', async () => {
    const mockClient = createMockClient();
    const { server } = createServer({ config: mockConfig, client: mockClient });
    const rawCallHandler = server._requestHandlers.get('tools/call');
    const callHandler = (payload) => rawCallHandler({ method: 'tools/call', ...payload });

    // 1. flowsync_list_workspaces
    const wsListRes = await callHandler({ params: { name: 'flowsync_list_workspaces', arguments: {} } });
    assert.match(wsListRes.content[0].text, /Acme Core/);
    assert.equal(wsListRes.structuredContent.workspaces.length, 1);

    // 2. flowsync_get_workspace
    const wsGetRes = await callHandler({ params: { name: 'flowsync_get_workspace', arguments: { workspace_id: 1 } } });
    assert.match(wsGetRes.content[0].text, /Workspace: Acme Core/);
    assert.equal(wsGetRes.structuredContent.workspace.slug, 'acme-core');

    // 3. flowsync_list_projects
    const projListRes = await callHandler({ params: { name: 'flowsync_list_projects', arguments: {} } });
    assert.match(projListRes.content[0].text, /Web App/);
    assert.equal(projListRes.structuredContent.projects[0].key, 'WEB');

    // 4. flowsync_get_project
    const projGetRes = await callHandler({ params: { name: 'flowsync_get_project', arguments: { project_id: 10 } } });
    assert.match(projGetRes.content[0].text, /Project: Web App/);
    assert.equal(projGetRes.structuredContent.project.statuses.length, 2);

    // 5. flowsync_list_tasks (asserts view=list is sent)
    const taskListRes = await callHandler({ params: { name: 'flowsync_list_tasks', arguments: { project_id: 10 } } });
    assert.match(taskListRes.content[0].text, /\[WEB-1\] Setup auth/);
    const lastCall = mockClient.calls[mockClient.calls.length - 1];
    assert.equal(lastCall.params.view, 'list');

    // 6. flowsync_get_task by task_key
    const taskGetResKey = await callHandler({
        params: { name: 'flowsync_get_task', arguments: { project_id: 10, task_key: 'WEB-1' } },
    });
    assert.match(taskGetResKey.content[0].text, /\[WEB-1\] Setup auth/);
    assert.equal(taskGetResKey.structuredContent.task.id, 1001);

    // 7. flowsync_get_task by task_id
    const taskGetResId = await callHandler({
        params: { name: 'flowsync_get_task', arguments: { project_id: 10, task_id: 1001 } },
    });
    assert.match(taskGetResId.content[0].text, /\[WEB-1\] Setup auth/);

    // 8. flowsync_get_task requires either task_id or task_key
    const missingId = await callHandler({ params: { name: 'flowsync_get_task', arguments: { project_id: 10 } } });
    assert.equal(missingId.isError, true);
    assert.match(missingId.content[0].text, /Either task_id or task_key must be provided/);

    // 9. flowsync_get_task_transitions
    const transRes = await callHandler({
        params: { name: 'flowsync_get_task_transitions', arguments: { project_id: 10, task_id: 1001 } },
    });
    assert.match(transRes.content[0].text, /\[ALLOWED\] Done/);
    assert.equal(transRes.structuredContent.transitions.length, 1);

    // 10. flowsync_list_task_activity
    const actRes = await callHandler({
        params: { name: 'flowsync_list_task_activity', arguments: { project_id: 10, task_id: 1001 } },
    });
    assert.match(actRes.content[0].text, /created task/);

    // 11. flowsync_list_comments
    const comRes = await callHandler({
        params: { name: 'flowsync_list_comments', arguments: { project_id: 10, task_id: 1001 } },
    });
    assert.match(comRes.content[0].text, /Working on it/);

    // 12. flowsync_list_worklogs
    const wlRes = await callHandler({
        params: { name: 'flowsync_list_worklogs', arguments: { project_id: 10, task_id: 1001 } },
    });
    assert.match(wlRes.content[0].text, /Coding/);

    // 13. flowsync_list_sprints
    const spRes = await callHandler({
        params: { name: 'flowsync_list_sprints', arguments: { project_id: 10 } },
    });
    assert.match(spRes.content[0].text, /Sprint 1/);

    // 14. flowsync_search_tasks
    const srchRes = await callHandler({
        params: { name: 'flowsync_search_tasks', arguments: { q: 'auth' } },
    });
    assert.match(srchRes.content[0].text, /\[WEB-1\] Setup auth/);
});

test('Resources provider serves static architecture contexts and dynamic entity URIs', async () => {
    const mockClient = createMockClient();
    const { server } = createServer({ config: mockConfig, client: mockClient });
    const rawListHandler = server._requestHandlers.get('resources/list');
    const listHandler = (payload = {}) => rawListHandler({ method: 'resources/list', ...payload });
    const rawReadHandler = server._requestHandlers.get('resources/read');
    const readHandler = (payload) => rawReadHandler({ method: 'resources/read', ...payload });

    // List resources
    const listRes = await listHandler({});
    const uris = listRes.resources.map((r) => r.uri);
    assert.ok(uris.includes('flowsync://context/architecture'));
    assert.ok(uris.includes('flowsync://context/conventions'));

    // Read static resource
    const archRes = await readHandler({ params: { uri: 'flowsync://context/architecture' } });
    assert.match(archRes.contents[0].text, /FlowSync Multi-Tenant Architecture/);
    assert.equal(archRes.contents[0].mimeType, 'text/markdown');

    // Read dynamic project resource
    const dynProjRes = await readHandler({ params: { uri: 'flowsync://project/10' } });
    assert.equal(dynProjRes.contents[0].mimeType, 'application/json');
    const parsedProj = JSON.parse(dynProjRes.contents[0].text);
    assert.equal(parsedProj.id, 10);
    assert.equal(parsedProj.key, 'WEB');

    // Read dynamic task resource
    const dynTaskRes = await readHandler({ params: { uri: 'flowsync://task/10/WEB-1' } });
    assert.equal(dynTaskRes.contents[0].mimeType, 'application/json');
    const parsedTask = JSON.parse(dynTaskRes.contents[0].text);
    assert.equal(parsedTask.key, 'WEB-1');

    // Unknown resource throws InvalidRequest McpError
    await assert.rejects(
        () => readHandler({ params: { uri: 'flowsync://unknown/123' } }),
        (err) => err instanceof McpError && /Resource not found/.test(err.message)
    );
});
