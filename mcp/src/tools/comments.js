/**
 * Task comments tools for FlowSync MCP.
 */

export const listCommentsTool = {
    name: 'flowsync_list_comments',
    description: 'List comments on a task including author and thread replies.',
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
        const { data } = await client.get(`projects/${args.project_id}/tasks/${args.task_id}/comments`);
        const comments = data.comments || [];

        const formatComment = (c, indent = '') => {
            const author = c.user?.name || 'Unknown User';
            const edited = c.edited_at ? ' (edited)' : '';
            let line = `${indent}[${c.created_at}] ${author}${edited}: ${c.comment}`;
            if (c.replies?.length > 0) {
                const replyLines = c.replies.map((r) => formatComment(r, indent + '  ')).join('\n');
                line += '\n' + replyLines;
            }
            return line;
        };

        const summary = comments.length === 0
            ? 'No comments on this task.'
            : comments.map((c) => formatComment(c)).join('\n\n');

        return {
            content: [{ type: 'text', text: summary }],
            structuredContent: { comments },
        };
    },
};
