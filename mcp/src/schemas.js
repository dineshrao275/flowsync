/**
 * JSON Schemas shared by MCP tools and resources.
 */

export const PaginationSchema = {
    type: 'object',
    properties: {
        page: {
            type: 'integer',
            description: 'Page number to retrieve (1-based)',
            minimum: 1,
            default: 1,
        },
        per_page: {
            type: 'integer',
            description: 'Number of items per page (maximum 100)',
            minimum: 1,
            maximum: 100,
            default: 25,
        },
    },
};

export const TaskFilterSchema = {
    type: 'object',
    properties: {
        ...PaginationSchema.properties,
        status_id: {
            type: 'integer',
            description: 'Filter by task status ID',
        },
        priority_id: {
            type: 'integer',
            description: 'Filter by priority ID',
        },
        assignee_id: {
            type: 'integer',
            description: 'Filter by assignee user ID',
        },
        q: {
            type: 'string',
            description: 'Text search query matching title or description',
        },
        sprint: {
            type: 'integer',
            description: 'Filter by sprint ID',
        },
    },
};
