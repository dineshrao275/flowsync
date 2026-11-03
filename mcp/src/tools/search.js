/**
 * Task search tool for FlowSync MCP.
 */

import { UNTRUSTED_NOTE } from '../text.js';

export const searchTasksTool = {
    name: 'flowsync_search_tasks',
    description: `Search tasks across all projects visible to the caller. Requires the global_search module. ${UNTRUSTED_NOTE}`,
    inputSchema: {
        type: 'object',
        required: ['q'],
        properties: {
            q: {
                type: 'string',
                description: 'Search keyword query',
            },
            project_id: {
                type: 'integer',
                description: 'Optional filter by project ID',
            },
            status_id: {
                type: 'integer',
                description: 'Optional filter by status ID',
            },
            per_page: {
                type: 'integer',
                description: 'Results per page (maximum 100)',
                default: 25,
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
        const { data } = await client.get('search/tasks', { params: args });
        const tasks = data.tasks || [];

        const summary = tasks.length === 0
            ? `No tasks found matching "${args.q}".`
            : tasks.map((t) => `- [${t.key}] ${t.title} (Project: ${t.project?.name || t.project_id}, Status: ${t.status?.name || t.status_id})`).join('\n');

        return {
            content: [{ type: 'text', text: summary }],
            structuredContent: { tasks },
        };
    },
};
