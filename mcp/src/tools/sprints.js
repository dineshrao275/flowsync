/**
 * Sprints tools for FlowSync MCP.
 */

export const listSprintsTool = {
    name: 'flowsync_list_sprints',
    description: 'List sprints in a project. Requires the sprints module.',
    inputSchema: {
        type: 'object',
        required: ['project_id'],
        properties: {
            project_id: {
                type: 'integer',
                description: 'Project ID',
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
        const { data } = await client.get(`projects/${args.project_id}/sprints`);
        const sprints = data.sprints || [];

        const summary = sprints.length === 0
            ? 'No sprints found for this project.'
            : sprints.map((s) => `- ${s.name} [Status: ${s.status}] (id: ${s.id}, tasks: ${s.tasks_count ?? s.tasks?.length ?? 0})`).join('\n');

        return {
            content: [{ type: 'text', text: summary }],
            structuredContent: { sprints },
        };
    },
};
