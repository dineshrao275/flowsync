/**
 * Tool definition for flowsync_me.
 */

export const meTool = {
    name: 'flowsync_me',
    description: 'Inspect the authenticated user, tenant, token abilities, active modules, and API quota usage.',
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
        const { data } = await client.get('me');
        const user = data.user || {};
        const tenant = data.tenant || {};
        const token = data.token || {};
        const usage = data.usage?.api_calls || {};

        const textSummary = [
            `Authenticated as: ${user.name} (${user.email}) [User ID: ${user.id}]`,
            `Tenant: ${tenant.name} (${tenant.slug}) [Tenant ID: ${tenant.id}]`,
            `Token: "${token.name}"`,
            `Abilities: ${(token.abilities || []).join(', ') || 'none'} (Write access: ${token.can_write ? 'Yes' : 'No'})`,
            `Monthly API Usage: ${usage.used ?? 0} / ${usage.limit ?? 'unlimited'} calls`,
            `Active Modules: ${(data.modules || []).join(', ') || 'all'}`,
        ].join('\n');

        return {
            content: [
                {
                    type: 'text',
                    text: textSummary,
                },
            ],
            structuredContent: data,
        };
    },
};
