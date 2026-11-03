/**
 * Task comments tools for FlowSync MCP.
 */

import { UNTRUSTED_NOTE, UNTRUSTED_BANNER } from '../text.js';

/** The server returns the whole thread unpaginated; the tool caps what reaches the agent's context. */
export const MAX_COMMENTS = 100;

export const listCommentsTool = {
    name: 'flowsync_list_comments',
    description: `List comments on a task including author and thread replies (the newest ${MAX_COMMENTS} comments at most; the result says how many were left out). ${UNTRUSTED_NOTE}`,
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
        const all = data.comments || [];
        const count = (list) => list.reduce((n, c) => n + 1 + count(c.replies || []), 0);
        const total = count(all);

        // Keep the newest threads whole until the cap is reached, rather than cutting a thread in half.
        const comments = [];
        let kept = 0;
        for (const thread of [...all].reverse()) {
            const size = count([thread]);
            if (comments.length > 0 && kept + size > MAX_COMMENTS) break;
            comments.unshift(thread);
            kept += size;
        }
        const truncated = kept < total;

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

        const body = comments.length === 0
            ? 'No comments on this task.'
            : comments.map((c) => formatComment(c)).join('\n\n');
        const summary = comments.length === 0
            ? body
            : `${UNTRUSTED_BANNER}\n\n${body}${truncated ? `\n\n… ${total - kept} older comment(s) not shown (${total} in total).` : ''}`;

        return {
            content: [{ type: 'text', text: summary }],
            structuredContent: { comments, total, shown: kept, truncated },
        };
    },
};
