import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from '../src/index.js';
import { FlowSyncClient } from '../src/client.js';
import {
    ListToolsRequestSchema,
    CallToolRequestSchema,
    McpError,
} from '@modelcontextprotocol/sdk/types.js';

function createMockWriteClient() {
    const calls = [];
    return {
        calls,
        async post(endpoint, body = {}, options = {}) {
            calls.push({ method: 'POST', endpoint, body, options });

            if (endpoint === 'projects/10/tasks') {
                return {
                    status: 201,
                    data: {
                        task: {
                            id: 2001,
                            key: 'WEB-42',
                            title: body.title,
                            status: { name: 'To Do', id: body.status_id || 1 },
                            priority: { name: 'High' },
                        },
                    },
                };
            }

            if (endpoint === 'projects/10/tasks/2001/move') {
                if (body.status_id === 999) {
                    // Requires workflow manager approval (HTTP 202)
                    return {
                        status: 202,
                        data: {
                            message: "This move needs a workflow manager's approval — it has been requested.",
                            pending_approval: true,
                            request_id: 88,
                        },
                    };
                }

                // Normal successful move (HTTP 200)
                return {
                    status: 200,
                    data: {
                        message: 'Task moved.',
                        task: {
                            id: 2001,
                            key: 'WEB-42',
                            status: { name: 'In Progress', id: body.status_id },
                        },
                    },
                };
            }

            if (endpoint === 'projects/10/tasks/2001/comments') {
                return {
                    status: 201,
                    data: {
                        comment: {
                            id: 777,
                            task_id: 2001,
                            comment: body.comment,
                            parent_id: body.parent_id || null,
                            created_at: '2026-11-02T12:00:00Z',
                        },
                    },
                };
            }

            if (endpoint === 'projects/10/tasks/2001/work-logs') {
                return {
                    status: 201,
                    data: {
                        work_log: {
                            id: 555,
                            task_id: 2001,
                            description: body.description,
                            duration_minutes: 60,
                            started_at: body.started_at,
                            ended_at: body.ended_at,
                            billable: body.billable,
                        },
                    },
                };
            }

            throw new Error(`Unhandled mock POST endpoint: ${endpoint}`);
        },

        async put(endpoint, body = {}, options = {}) {
            calls.push({ method: 'PUT', endpoint, body, options });

            if (endpoint === 'projects/10/tasks/2001') {
                return {
                    status: 200,
                    data: {
                        task: {
                            id: 2001,
                            key: 'WEB-42',
                            title: body.title || 'Original Title',
                            description: body.description,
                        },
                    },
                };
            }

            throw new Error(`Unhandled mock PUT endpoint: ${endpoint}`);
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

test('All Phase 4 Write Tools are registered with correct annotations', async () => {
    const mockClient = createMockWriteClient();
    const { server } = createServer({ config: mockConfig, client: mockClient });

    const handler = server._requestHandlers.get('tools/list');
    const toolsResponse = await handler({ method: 'tools/list' });
    const tools = toolsResponse.tools;

    const writeToolNames = [
        'flowsync_create_task',
        'flowsync_update_task',
        'flowsync_move_task',
        'flowsync_add_comment',
        'flowsync_log_time',
    ];

    for (const name of writeToolNames) {
        const tool = tools.find((t) => t.name === name);
        assert.ok(tool, `Missing write tool: ${name}`);
        assert.equal(tool.annotations.readOnlyHint, false, `${name} should have readOnlyHint: false`);
        assert.equal(tool.annotations.destructiveHint, false, `${name} should have destructiveHint: false`);
        assert.equal(tool.annotations.idempotentHint, true, `${name} should have idempotentHint: true`);
        assert.equal(tool.annotations.openWorldHint, false, `${name} should have openWorldHint: false`);
    }
});

test('Write tools execute correctly and format both text and structured content', async () => {
    const mockClient = createMockWriteClient();
    const { server } = createServer({ config: mockConfig, client: mockClient });
    const rawCallHandler = server._requestHandlers.get('tools/call');
    const callHandler = (payload) => rawCallHandler({ method: 'tools/call', ...payload });

    // 1. flowsync_create_task
    const createRes = await callHandler({
        params: {
            name: 'flowsync_create_task',
            arguments: {
                project_id: 10,
                title: 'New agent task',
                description: 'Created by MCP agent',
                priority_id: 2,
            },
        },
    });
    assert.match(createRes.content[0].text, /Task created successfully: \[WEB-42\] New agent task/);
    assert.equal(createRes.structuredContent.task.id, 2001);
    assert.equal(mockClient.calls[0].body.title, 'New agent task');

    // 2. flowsync_update_task
    const updateRes = await callHandler({
        params: {
            name: 'flowsync_update_task',
            arguments: {
                project_id: 10,
                task_id: 2001,
                title: 'Updated title',
                description: 'Updated desc',
            },
        },
    });
    assert.match(updateRes.content[0].text, /Task updated successfully: \[WEB-42\] Updated title/);
    assert.equal(updateRes.structuredContent.task.title, 'Updated title');

    // 3. flowsync_move_task - direct move (HTTP 200)
    const moveRes200 = await callHandler({
        params: {
            name: 'flowsync_move_task',
            arguments: {
                project_id: 10,
                task_id: 2001,
                status_id: 5,
            },
        },
    });
    assert.match(moveRes200.content[0].text, /Task moved successfully to status "In Progress"/);
    assert.equal(moveRes200.structuredContent.pending_approval, false);

    // 4. flowsync_move_task - requires approval (HTTP 202)
    const moveRes202 = await callHandler({
        params: {
            name: 'flowsync_move_task',
            arguments: {
                project_id: 10,
                task_id: 2001,
                status_id: 999,
            },
        },
    });
    assert.match(moveRes202.content[0].text, /Move requested, NOT done — a workflow manager must approve request #88/);
    assert.equal(moveRes202.structuredContent.pending_approval, true);
    assert.equal(moveRes202.structuredContent.request_id, 88);

    // 5. flowsync_add_comment
    const commentRes = await callHandler({
        params: {
            name: 'flowsync_add_comment',
            arguments: {
                project_id: 10,
                task_id: 2001,
                comment: 'Automated comment from Claude Code',
            },
        },
    });
    assert.match(commentRes.content[0].text, /Comment added to task #2001 \(Comment ID: 777\)/);
    assert.equal(commentRes.structuredContent.comment.id, 777);

    // 6. flowsync_log_time with duration_minutes auto-calculating interval
    const timeRes = await callHandler({
        params: {
            name: 'flowsync_log_time',
            arguments: {
                project_id: 10,
                task_id: 2001,
                description: 'Refactoring tests',
                duration_minutes: 60,
            },
        },
    });
    assert.match(timeRes.content[0].text, /Time logged for task #2001: 60 min - "Refactoring tests"/);
    assert.equal(timeRes.structuredContent.work_log.id, 555);

    // Verify auto-calculated timestamps were passed in the payload
    const logCall = mockClient.calls[mockClient.calls.length - 1];
    assert.ok(logCall.body.started_at, 'started_at should be auto-calculated');
    assert.ok(logCall.body.ended_at, 'ended_at should be auto-calculated');
    assert.equal(logCall.body.billable, true);
});

test('FLOWSYNC_READ_ONLY blocks write tools before any HTTP call is made', async () => {
    const mockLogger = { debug: () => {}, info: () => {}, warn: () => {}, error: () => {} };
    const readOnlyClient = new FlowSyncClient({
        baseUrl: 'http://localhost',
        token: 'fst_1_mock',
        timeoutMs: 1000,
        readOnly: true,
    }, mockLogger);

    const { server } = createServer({
        config: { ...mockConfig, readOnly: true },
        client: readOnlyClient,
    });
    const rawCallHandler = server._requestHandlers.get('tools/call');
    const callHandler = (payload) => rawCallHandler({ method: 'tools/call', ...payload });

    await assert.rejects(
        () => callHandler({
            params: {
                name: 'flowsync_create_task',
                arguments: { project_id: 10, title: 'Blocked write' },
            },
        }),
        (err) => err instanceof McpError && /read-only mode/.test(err.message)
    );

    await assert.rejects(
        () => callHandler({
            params: {
                name: 'flowsync_move_task',
                arguments: { project_id: 10, task_id: 2001, status_id: 2 },
            },
        }),
        (err) => err instanceof McpError && /read-only mode/.test(err.message)
    );
});
