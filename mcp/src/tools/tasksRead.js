/**
 * Task read tools for FlowSync MCP.
 */

import { TaskFilterSchema } from '../schemas.js';

export const listTasksTool = {
    name: 'flowsync_list_tasks',
    description: 'List tasks in a project with filtering and page-based pagination. Always queries with view=list.',
    inputSchema: {
        type: 'object',
        required: ['project_id'],
        properties: {
            project_id: {
                type: 'integer',
                description: 'Project ID',
            },
            ...TaskFilterSchema.properties,
        },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        const { project_id, ...params } = args;

        // Force view=list for pagination support
        const queryParams = {
            ...params,
            view: 'list',
        };

        const { data } = await client.get(`projects/${project_id}/tasks`, { params: queryParams });
        const tasks = data.tasks || [];
        const pagination = data.pagination || {};

        const listText = tasks.length === 0
            ? 'No tasks found matching criteria.'
            : tasks.map((t) => `- [${t.key}] ${t.title} (ID: ${t.id}, Status: ${t.status?.name || t.status_id}, Priority: ${t.priority?.name || t.priority_id || 'None'})`).join('\n');

        const pageSummary = pagination.total !== undefined
            ? `Page ${pagination.current_page || 1} of ${pagination.last_page || 1} (${pagination.total} total tasks)`
            : `${tasks.length} tasks returned`;

        return {
            content: [
                {
                    type: 'text',
                    text: `${listText}\n\n${pageSummary}`,
                },
            ],
            structuredContent: {
                tasks,
                pagination,
            },
        };
    },
};

export const getTaskTool = {
    name: 'flowsync_get_task',
    description: 'Get full task details by task numeric ID or project-scoped key (e.g. key="APP-12").',
    inputSchema: {
        type: 'object',
        required: ['project_id'],
        properties: {
            project_id: {
                type: 'integer',
                description: 'Project ID',
            },
            task_id: {
                type: 'integer',
                description: 'Numeric Task ID (either task_id or task_key is required)',
            },
            task_key: {
                type: 'string',
                description: 'Task key (e.g. APP-42) (either task_id or task_key is required)',
            },
        },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        let path;
        if (args.task_key) {
            path = `projects/${args.project_id}/tasks/key/${encodeURIComponent(args.task_key)}`;
        } else if (args.task_id) {
            path = `projects/${args.project_id}/tasks/${args.task_id}`;
        } else {
            throw new Error('Either task_id or task_key must be provided.');
        }

        const { data } = await client.get(path);
        const task = data.task || {};

        // Truncate description if excessively long (> 50KB)
        let description = task.description || '';
        if (description.length > 50000) {
            description = description.substring(0, 50000) + '\n... [description truncated at 50KB]';
        }

        const text = [
            `Task [${task.key}] ${task.title} (ID: ${task.id})`,
            `Status: ${task.status?.name || task.status_id}`,
            `Priority: ${task.priority?.name || task.priority_id || 'None'}`,
            `Assignee: ${task.assignee?.name || task.assignee_id || 'Unassigned'}`,
            task.due_date ? `Due Date: ${task.due_date}` : null,
            task.story_points ? `Story Points: ${task.story_points}` : null,
            `Created: ${task.created_at}`,
            `\nDescription:\n${description || '(No description provided)'}`,
        ].filter(Boolean).join('\n');

        return {
            content: [{ type: 'text', text }],
            structuredContent: { task },
        };
    },
};

export const getTaskTransitionsTool = {
    name: 'flowsync_get_task_transitions',
    description: 'Get allowed workflow target statuses and blocker reasons for a task before moving it.',
    inputSchema: {
        type: 'object',
        required: ['project_id', 'task_id'],
        properties: {
            project_id: {
                type: 'integer',
                description: 'Project ID',
            },
            task_id: {
                type: 'integer',
                description: 'Task ID',
            },
        },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        const { data } = await client.get(`projects/${args.project_id}/tasks/${args.task_id}/transitions`);
        const transitions = data.transitions || [];

        const summary = transitions.length === 0
            ? 'No transition targets available.'
            : transitions.map((t) => {
                const mark = t.allowed ? '[ALLOWED]' : '[BLOCKED]';
                const reason = t.blocked_reason ? ` (Reason: ${t.blocked_reason})` : '';
                return `${mark} ${t.name} (Status ID: ${t.status_id})${reason}`;
            }).join('\n');

        return {
            content: [{ type: 'text', text: summary }],
            structuredContent: { transitions },
        };
    },
};

export const listTaskActivityTool = {
    name: 'flowsync_list_task_activity',
    description: 'List recent activity history and audit records for a task.',
    inputSchema: {
        type: 'object',
        required: ['project_id', 'task_id'],
        properties: {
            project_id: {
                type: 'integer',
                description: 'Project ID',
            },
            task_id: {
                type: 'integer',
                description: 'Task ID',
            },
        },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        const { data } = await client.get(`projects/${args.project_id}/tasks/${args.task_id}/activities`);
        const activities = data.activities || [];

        const summary = activities.length === 0
            ? 'No recorded activity for this task.'
            : activities.map((a) => {
                const actor = a.actor?.name || 'System';
                const via = a.data?.via === 'api' ? ' (via API)' : '';
                return `[${a.created_at}] ${actor}${via}: ${a.action}`;
            }).join('\n');

        return {
            content: [{ type: 'text', text: summary }],
            structuredContent: { activities },
        };
    },
};
