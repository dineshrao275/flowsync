/**
 * FlowSync MCP Prompts.
 *
 * Implements plan_task, implement_task, review_task, and daily_digest.
 */

export const prompts = [
    {
        name: 'plan_task',
        description: 'Fetch task details and repository conventions to produce a structured, phased implementation plan.',
        arguments: [
            {
                name: 'project_id',
                description: 'Project ID',
                required: true,
            },
            {
                name: 'task_key',
                description: 'Task key (e.g. PROJ-123)',
                required: true,
            },
        ],
        async execute(args, client) {
            const { project_id, task_key } = args;
            let taskSummary = '';

            try {
                const { data } = await client.get(`projects/${project_id}/tasks/key/${encodeURIComponent(task_key)}`);
                const task = data.task || {};
                taskSummary = `Task [${task.key}] ${task.title}\nStatus: ${task.status?.name || 'N/A'}\nPriority: ${task.priority?.name || 'N/A'}\nAssignee: ${task.assignee?.name || 'Unassigned'}\n\nDescription:\n${task.description || '(No description)'}`;
            } catch (err) {
                taskSummary = `(Unable to load live task details: ${err.message})`;
            }

            return {
                description: `Plan implementation for task ${task_key}`,
                messages: [
                    {
                        role: 'user',
                        content: {
                            type: 'text',
                            text: `You are planning the implementation of FlowSync task ${task_key}.

${taskSummary}

Please review the requirements above and follow these instructions:
1. Break down the task into logical, verifiable phases.
2. Identify which architectural boundaries, databases, models, routes, or components will be touched.
3. Review repository conventions (flowsync://context/conventions) and tenant isolation rules.
4. Highlight any edge cases, authorization requirements, or tests needed before executing.`,
                        },
                    },
                ],
            };
        },
    },

    {
        name: 'implement_task',
        description: 'Prepare instructions to implement a specific task including acceptance criteria and testing guidelines.',
        arguments: [
            {
                name: 'project_id',
                description: 'Project ID',
                required: true,
            },
            {
                name: 'task_key',
                description: 'Task key (e.g. PROJ-123)',
                required: true,
            },
        ],
        async execute(args, client) {
            const { project_id, task_key } = args;
            let taskSummary = '';

            try {
                const { data } = await client.get(`projects/${project_id}/tasks/key/${encodeURIComponent(task_key)}`);
                const task = data.task || {};
                taskSummary = `Task [${task.key}] ${task.title}\nStatus: ${task.status?.name || 'N/A'}\nPriority: ${task.priority?.name || 'N/A'}\nAssignee: ${task.assignee?.name || 'Unassigned'}\n\nDescription:\n${task.description || '(No description)'}`;
            } catch (err) {
                taskSummary = `(Unable to load live task details: ${err.message})`;
            }

            return {
                description: `Implement task ${task_key}`,
                messages: [
                    {
                        role: 'user',
                        content: {
                            type: 'text',
                            text: `You are implementing FlowSync task ${task_key}.

${taskSummary}

Implementation guidelines:
1. Write or update focused tests covering the expected behavior first.
2. Implement the minimum clean code required to satisfy requirements and pass tests.
3. Respect all tenant isolation boundaries and permission checks.
4. Run style linters and test suites before finishing.
5. Log time and update task comments or status via FlowSync tools when finished.`,
                        },
                    },
                ],
            };
        },
    },

    {
        name: 'review_task',
        description: 'Prepare a code review prompt inspecting task requirements, acceptance criteria, and recent discussions.',
        arguments: [
            {
                name: 'project_id',
                description: 'Project ID',
                required: true,
            },
            {
                name: 'task_key',
                description: 'Task key (e.g. PROJ-123)',
                required: true,
            },
        ],
        async execute(args, client) {
            const { project_id, task_key } = args;
            let taskSummary = '';
            let commentsSummary = '';

            try {
                const { data } = await client.get(`projects/${project_id}/tasks/key/${encodeURIComponent(task_key)}`);
                const task = data.task || {};
                taskSummary = `Task [${task.key}] ${task.title}\nStatus: ${task.status?.name || 'N/A'}\nDescription:\n${task.description || '(No description)'}`;

                if (task.id) {
                    const comRes = await client.get(`projects/${project_id}/tasks/${task.id}/comments`);
                    const comments = comRes.data.comments || [];
                    if (comments.length > 0) {
                        commentsSummary = '\n\nRecent Comments:\n' + comments.slice(-3).map((c) => `- ${c.user?.name || 'User'}: ${c.comment}`).join('\n');
                    }
                }
            } catch (err) {
                taskSummary = `(Unable to load live task details: ${err.message})`;
            }

            return {
                description: `Review task ${task_key}`,
                messages: [
                    {
                        role: 'user',
                        content: {
                            type: 'text',
                            text: `Please review the implementation for FlowSync task ${task_key}.

${taskSummary}${commentsSummary}

Review checklist:
1. Does the code fulfill all requirements described in the task?
2. Are tests present, comprehensive, and passing?
3. Are there any security vulnerabilities, prompt injection risks, or permission leaks?
4. Are coding standards and Pint/ESLint styling guidelines adhered to?
5. State clearly whether this task is ready to transition to Done or In Review.`,
                        },
                    },
                ],
            };
        },
    },

    {
        name: 'daily_digest',
        description: 'Generate a daily summary of high-priority tasks and recent activity.',
        arguments: [
            {
                name: 'project_id',
                description: 'Optional Project ID to restrict summary to a specific project',
                required: false,
            },
        ],
        async execute(args, client) {
            let taskListSummary = '';

            try {
                if (args.project_id) {
                    const { data } = await client.get(`projects/${args.project_id}/tasks`, {
                        params: { view: 'list', per_page: 25 },
                    });
                    const tasks = data.tasks || [];
                    taskListSummary = tasks.slice(0, 10).map((t) => `- [${t.key}] ${t.title} (${t.status?.name || 'Status'}, Priority: ${t.priority?.name || 'Normal'})`).join('\n');
                } else {
                    const { data } = await client.get('workspaces');
                    taskListSummary = `Visible workspaces: ${(data.workspaces || []).map((w) => w.name).join(', ')}`;
                }
            } catch (err) {
                taskListSummary = `(Unable to load tasks: ${err.message})`;
            }

            return {
                description: 'Daily activity digest',
                messages: [
                    {
                        role: 'user',
                        content: {
                            type: 'text',
                            text: `Produce a concise daily digest of work items.

Active Items:
${taskListSummary || 'No active items loaded.'}

Please summarize:
1. Top 3 priority tasks needing attention today.
2. Any blocked or overdue items.
3. Recommended next actions for the team.`,
                        },
                    },
                ],
            };
        },
    },
];
