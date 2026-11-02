/**
 * Task write tools for FlowSync MCP.
 *
 * Implements create, update, move, comment, and log_time.
 * All write tools send Idempotency-Key headers automatically via FlowSyncClient.
 */

export const createTaskTool = {
    name: 'flowsync_create_task',
    description: 'Create a new task in a project. Requires tms.write ability. Modifies project task board.',
    inputSchema: {
        type: 'object',
        required: ['project_id', 'title'],
        properties: {
            project_id: {
                type: 'integer',
                description: 'Project ID',
            },
            title: {
                type: 'string',
                description: 'Task title / summary (max 255 chars)',
                maxLength: 255,
            },
            description: {
                type: 'string',
                description: 'Detailed description of the task (Markdown supported)',
            },
            status_id: {
                type: 'integer',
                description: 'Initial status ID (defaults to project\'s first status)',
            },
            priority_id: {
                type: 'integer',
                description: 'Priority ID (e.g. Low, Medium, High, Urgent)',
            },
            assignee_id: {
                type: 'integer',
                description: 'User ID of the assigned team member',
            },
            parent_id: {
                type: 'integer',
                description: 'Parent task ID for subtasks',
            },
            epic_id: {
                type: 'integer',
                description: 'Epic task ID if organizing under an epic',
            },
            labels: {
                type: 'array',
                items: { type: 'integer' },
                description: 'Array of label IDs',
            },
            start_date: {
                type: 'string',
                description: 'Start date in YYYY-MM-DD format',
            },
            due_date: {
                type: 'string',
                description: 'Due date in YYYY-MM-DD format',
            },
            story_points: {
                type: 'number',
                description: 'Story points estimate (e.g. 1, 2, 3, 5, 8)',
            },
            issue_type_id: {
                type: 'integer',
                description: 'Issue type ID (e.g. Bug, Task, Story, Epic)',
            },
            version_id: {
                type: 'integer',
                description: 'Release / version ID',
            },
            components: {
                type: 'array',
                items: { type: 'integer' },
                description: 'Array of component IDs',
            },
            estimate_minutes: {
                type: 'integer',
                description: 'Estimated time in minutes',
            },
        },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        const { project_id, ...payload } = args;
        const { data } = await client.post(`projects/${project_id}/tasks`, payload);
        const task = data.task || {};

        const text = `Task created successfully: [${task.key}] ${task.title} (ID: ${task.id}, Status: ${task.status?.name || task.status_id})`;

        return {
            content: [{ type: 'text', text }],
            structuredContent: { task },
        };
    },
};

export const updateTaskTool = {
    name: 'flowsync_update_task',
    description: 'Update an existing task\'s fields. Requires tms.write ability. Modifies task attributes.',
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
            title: {
                type: 'string',
                description: 'Updated task title (max 255 chars)',
                maxLength: 255,
            },
            description: {
                type: 'string',
                description: 'Updated description (Markdown supported)',
            },
            status_id: {
                type: 'integer',
                description: 'Updated status ID. Note: if workflow rules require approval or blockers exist, prefer flowsync_move_task or check flowsync_get_task_transitions first.',
            },
            priority_id: {
                type: 'integer',
                description: 'Updated priority ID',
            },
            assignee_id: {
                type: 'integer',
                description: 'Updated assignee user ID',
            },
            parent_id: {
                type: 'integer',
                description: 'Updated parent task ID',
            },
            epic_id: {
                type: 'integer',
                description: 'Updated epic task ID',
            },
            labels: {
                type: 'array',
                items: { type: 'integer' },
                description: 'Array of label IDs',
            },
            start_date: {
                type: 'string',
                description: 'Start date in YYYY-MM-DD format',
            },
            due_date: {
                type: 'string',
                description: 'Due date in YYYY-MM-DD format',
            },
            story_points: {
                type: 'number',
                description: 'Story points estimate',
            },
            issue_type_id: {
                type: 'integer',
                description: 'Issue type ID',
            },
            version_id: {
                type: 'integer',
                description: 'Version ID',
            },
            components: {
                type: 'array',
                items: { type: 'integer' },
                description: 'Array of component IDs',
            },
            estimate_minutes: {
                type: 'integer',
                description: 'Estimated minutes',
            },
        },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        const { project_id, task_id, ...payload } = args;
        const { data } = await client.put(`projects/${project_id}/tasks/${task_id}`, payload);
        const task = data.task || {};

        const text = `Task updated successfully: [${task.key}] ${task.title} (ID: ${task.id})`;

        return {
            content: [{ type: 'text', text }],
            structuredContent: { task },
        };
    },
};

export const moveTaskTool = {
    name: 'flowsync_move_task',
    description: 'Move a task to a different workflow status column. If workflow approval is required by project policy, returns HTTP 202 and records a pending request instead of completing immediately. Check flowsync_get_task_transitions first to inspect allowed target statuses.',
    inputSchema: {
        type: 'object',
        required: ['project_id', 'task_id', 'status_id'],
        properties: {
            project_id: {
                type: 'integer',
                description: 'Project ID',
            },
            task_id: {
                type: 'integer',
                description: 'Task ID',
            },
            status_id: {
                type: 'integer',
                description: 'Target status ID to move the task into',
            },
            index: {
                type: 'integer',
                description: 'Position / sort index within the target status column (0-based, optional)',
                minimum: 0,
            },
        },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        const { project_id, task_id, status_id, index } = args;
        const { status, data } = await client.post(`projects/${project_id}/tasks/${task_id}/move`, {
            status_id,
            index,
        });

        if (status === 202 || data.pending_approval) {
            const reqId = data.request_id || 'unknown';
            const text = `Move requested, NOT done — a workflow manager must approve request #${reqId}.`;

            return {
                content: [{ type: 'text', text }],
                structuredContent: {
                    pending_approval: true,
                    request_id: data.request_id,
                    message: data.message || text,
                },
            };
        }

        const task = data.task || {};
        const text = `Task moved successfully to status "${task.status?.name || status_id}". (Key: ${task.key || task_id})`;

        return {
            content: [{ type: 'text', text }],
            structuredContent: {
                pending_approval: false,
                task,
            },
        };
    },
};

export const addCommentTool = {
    name: 'flowsync_add_comment',
    description: 'Add a comment or reply to a task thread. Requires tms.write ability. Mentioning team members via @username will trigger notifications for them.',
    inputSchema: {
        type: 'object',
        required: ['project_id', 'task_id', 'comment'],
        properties: {
            project_id: {
                type: 'integer',
                description: 'Project ID',
            },
            task_id: {
                type: 'integer',
                description: 'Task ID',
            },
            comment: {
                type: 'string',
                description: 'Comment body text (Markdown supported, max 5000 chars)',
                maxLength: 5000,
            },
            parent_id: {
                type: 'integer',
                description: 'Optional parent comment ID if replying to an existing comment',
            },
        },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        const { project_id, task_id, comment, parent_id } = args;
        const { data } = await client.post(`projects/${project_id}/tasks/${task_id}/comments`, {
            comment,
            parent_id,
        });
        const created = data.comment || {};

        const text = `Comment added to task #${task_id} (Comment ID: ${created.id}).`;

        return {
            content: [{ type: 'text', text }],
            structuredContent: { comment: created },
        };
    },
};

export const logTimeTool = {
    name: 'flowsync_log_time',
    description: 'Log time spent on a task. Requires time_tracking module and tms.write ability. If started_at or ended_at are omitted, they can be computed from duration_minutes.',
    inputSchema: {
        type: 'object',
        required: ['project_id', 'task_id', 'description'],
        properties: {
            project_id: {
                type: 'integer',
                description: 'Project ID',
            },
            task_id: {
                type: 'integer',
                description: 'Task ID',
            },
            description: {
                type: 'string',
                description: 'Description of the work performed (max 1000 chars)',
                maxLength: 1000,
            },
            duration_minutes: {
                type: 'integer',
                description: 'Duration in minutes (e.g. 60 for 1 hour). Used to auto-calculate interval if started_at/ended_at omitted.',
                minimum: 1,
            },
            started_at: {
                type: 'string',
                description: 'Start timestamp (ISO-8601, e.g. 2026-11-02T10:00:00Z)',
            },
            ended_at: {
                type: 'string',
                description: 'End timestamp (ISO-8601, e.g. 2026-11-02T11:00:00Z). Must be after started_at.',
            },
            billable: {
                type: 'boolean',
                description: 'Whether the time is billable (default: true)',
                default: true,
            },
        },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        const { project_id, task_id, description, billable = true } = args;

        let startedAt = args.started_at;
        let endedAt = args.ended_at;

        if (!startedAt && !endedAt) {
            const minutes = args.duration_minutes || 30;
            const now = new Date();
            const start = new Date(now.getTime() - minutes * 60 * 1000);
            startedAt = start.toISOString();
            endedAt = now.toISOString();
        } else if (startedAt && args.duration_minutes && !endedAt) {
            const start = new Date(startedAt);
            endedAt = new Date(start.getTime() + args.duration_minutes * 60 * 1000).toISOString();
        } else if (!startedAt && endedAt && args.duration_minutes) {
            const end = new Date(endedAt);
            startedAt = new Date(end.getTime() - args.duration_minutes * 60 * 1000).toISOString();
        }

        const payload = {
            description,
            started_at: startedAt,
            ended_at: endedAt,
            billable,
        };

        const { data } = await client.post(`projects/${project_id}/tasks/${task_id}/work-logs`, payload);
        const workLog = data.work_log || {};

        const durationStr = workLog.duration_minutes ? `${workLog.duration_minutes} min` : 'logged';
        const text = `Time logged for task #${task_id}: ${durationStr} - "${description}".`;

        return {
            content: [{ type: 'text', text }],
            structuredContent: { work_log: workLog },
        };
    },
};
