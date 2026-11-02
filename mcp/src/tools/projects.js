/**
 * Project read tools for FlowSync MCP.
 */

export const listProjectsTool = {
    name: 'flowsync_list_projects',
    description: 'List all projects accessible to the authenticated user across their workspaces.',
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
        const { data } = await client.get('projects');
        const projects = data.projects || [];

        const summary = projects.length === 0
            ? 'No projects found.'
            : projects.map((p) => `- [${p.key}] ${p.name} (id: ${p.id}, workspace: ${p.workspace?.name || p.workspace_id})`).join('\n');

        return {
            content: [{ type: 'text', text: summary }],
            structuredContent: { projects },
        };
    },
};

export const getProjectTool = {
    name: 'flowsync_get_project',
    description: 'Get project details including workflow statuses, labels, and member directory.',
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
        const { data } = await client.get(`projects/${args.project_id}`);
        const p = data.project || {};
        const statuses = p.statuses || [];
        const members = p.members || [];

        const statusLines = statuses.map((s) => `  * ${s.name} (ID: ${s.id}, Category: ${s.category})`).join('\n');
        const memberLines = members.slice(0, 10).map((m) => `  * ${m.name} (${m.email})`).join('\n');

        const text = [
            `Project: ${p.name} [Key: ${p.key}] (ID: ${p.id})`,
            p.description ? `Description: ${p.description}` : null,
            `Workspace: ${p.workspace?.name || p.workspace_id}`,
            `Board Type: ${p.board_type || 'kanban'}`,
            statuses.length > 0 ? `Workflow Statuses:\n${statusLines}` : 'No statuses configured.',
            members.length > 0 ? `Members (${members.length}):\n${memberLines}${members.length > 10 ? '\n  ...and more' : ''}` : null,
        ].filter(Boolean).join('\n');

        return {
            content: [{ type: 'text', text }],
            structuredContent: { project: p },
        };
    },
};
