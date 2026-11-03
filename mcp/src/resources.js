/**
 * Resource providers for FlowSync MCP.
 */

import { htmlToMarkdown, truncate } from './text.js';

export const resources = [
    {
        uri: 'flowsync://context/architecture',
        name: 'FlowSync Architecture & Tenancy Summary',
        description: 'Multi-tenant architecture: central system database and isolated per-tenant databases.',
        mimeType: 'text/markdown',
        async read(client) {
            return `# FlowSync Multi-Tenant Architecture

FlowSync is a multi-tenant platform with database-level isolation:
- **Central System Database**: Stores platform configuration, subscriptions, tenant directories, and super admin users.
- **Tenant Databases**: Each tenant operates inside its own isolated database containing the full TMS and HRMS schemas.
- **Sessionless API**: All /api/v1 endpoints authenticate via personal Bearer tokens (fst_<tenantId>_<random>) which resolve the target tenant connection before routing to domain policies.
- **Zero Database Bypass**: The MCP server operates as an API client against /api/v1 and never queries databases directly.
`;
        },
    },
    {
        uri: 'flowsync://context/conventions',
        name: 'FlowSync Coding Conventions',
        description: 'Repository coding guidelines, testing requirements, and styling rules.',
        mimeType: 'text/markdown',
        async read(client) {
            return `# FlowSync Engineering Conventions

- **PHP Standards**: Laravel 12, strict types, Pint style enforcer.
- **Testing**: ParaTest (php artisan test --parallel) for parallel execution.
- **Frontend**: React 19 SPA, Tailwind CSS v4, custom theme variables, ESLint 9.
- **API Token Security**: Scoped capability groups (tms.read, tms.write), row-scoping via policies, idempotent writes via UUID headers.
`;
        },
    },
];

export async function resolveDynamicResource(uri, client) {
    // flowsync://project/{id}
    const projectMatch = uri.match(/^flowsync:\/\/project\/(\d+)$/);
    if (projectMatch) {
        const projectId = projectMatch[1];
        const { data } = await client.get(`projects/${projectId}`);
        return {
            uri,
            mimeType: 'application/json',
            text: JSON.stringify(data.project, null, 2),
        };
    }

    // flowsync://task/{projectId}/{key}
    const taskMatch = uri.match(/^flowsync:\/\/task\/(\d+)\/([A-Za-z0-9-]+)$/);
    if (taskMatch) {
        const [, projectId, key] = taskMatch;
        const { data } = await client.get(`projects/${projectId}/tasks/key/${encodeURIComponent(key)}`);
        // Same shape as flowsync_get_task: the app stores HTML, so hand the agent capped Markdown.
        const description = truncate(htmlToMarkdown(data.task?.description || ''));
        const task = { ...data.task, description: description.text, description_format: 'markdown', description_truncated: description.truncated };

        return {
            uri,
            mimeType: 'application/json',
            text: JSON.stringify(task, null, 2),
        };
    }

    return null;
}
