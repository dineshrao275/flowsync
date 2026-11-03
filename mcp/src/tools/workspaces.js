/**
 * Workspace read tools for FlowSync MCP.
 */

export const listWorkspacesTool = {
    name: 'flowsync_list_workspaces',
    description: 'List all workspaces accessible to the authenticated user.',
    inputSchema: {
        type: 'object',
        properties: {},
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    },
    async execute(args, client) {
        const { data } = await client.get('workspaces');
        const workspaces = data.workspaces || [];

        const summary = workspaces.length === 0
            ? 'No workspaces found.'
            : workspaces.map((w) => `- ${w.name} (id: ${w.id}, slug: ${w.slug})`).join('\n');

        return {
            content: [{ type: 'text', text: summary }],
            structuredContent: { workspaces },
        };
    },
};

export const getWorkspaceTool = {
    name: 'flowsync_get_workspace',
    description: 'Get details of a specific workspace by ID.',
    inputSchema: {
        type: 'object',
        required: ['workspace_id'],
        properties: {
            workspace_id: {
                type: 'integer',
                description: 'Workspace ID',
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
        const { data } = await client.get(`workspaces/${args.workspace_id}`);
        const ws = data.workspace || {};

        const text = [
            `Workspace: ${ws.name} (ID: ${ws.id}, Slug: ${ws.slug})`,
            ws.description ? `Description: ${ws.description}` : null,
            `Projects count: ${ws.projects_count ?? ws.projects?.length ?? 0}`,
        ].filter(Boolean).join('\n');

        return {
            content: [{ type: 'text', text }],
            structuredContent: { workspace: ws },
        };
    },
};
