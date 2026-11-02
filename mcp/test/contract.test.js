import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from '../src/index.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const openApiPath = path.resolve(__dirname, '../../docs/openapi-v1.json');
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
