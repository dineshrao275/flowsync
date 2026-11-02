/**
 * Work logs tools for FlowSync MCP.
 */

export const listWorklogsTool = {
    name: 'flowsync_list_worklogs',
    description: 'List work logs and time tracking records logged against a task. Requires time_tracking module.',
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
        const { data } = await client.get(`projects/${args.project_id}/tasks/${args.task_id}/work-logs`);
        const workLogs = data.work_logs || [];

        const summary = workLogs.length === 0
            ? 'No work logs recorded for this task.'
            : workLogs.map((w) => {
                const user = w.user?.name || 'User';
                const desc = w.description ? ` - "${w.description}"` : '';
                return `[${w.started_at}] ${user}: ${w.duration_minutes} minutes${desc}`;
            }).join('\n');

        return {
            content: [{ type: 'text', text: summary }],
            structuredContent: { work_logs: workLogs },
        };
    },
};
