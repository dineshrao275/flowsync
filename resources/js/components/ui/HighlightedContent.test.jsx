import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import HighlightedContent from './HighlightedContent';

describe('HighlightedContent component', () => {
    it('highlights mentions with @ symbol and hover support', () => {
        render(
            <MemoryRouter>
                <HighlightedContent content="Hey @Ada Lovelace, please check this!" />
            </MemoryRouter>
        );
        expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
        expect(screen.getByText('@')).toBeInTheDocument();
    });

    it('highlights task keys with clickable TaskBadge', () => {
        render(
            <MemoryRouter>
                <HighlightedContent content="Related to TASK-456 and PROJ-10" projectId={3} />
            </MemoryRouter>
        );
        const taskLink = screen.getByRole('link', { name: /TASK-456/i });
        expect(taskLink).toHaveAttribute('href', '/projects/3?tab=tasks&task=TASK-456');

        const projLink = screen.getByRole('link', { name: /PROJ-10/i });
        expect(projLink).toHaveAttribute('href', '/projects/3?tab=tasks&task=PROJ-10');
    });

    it('converts URLs into clickable external links', () => {
        render(
            <MemoryRouter>
                <HighlightedContent content="See documentation at https://example.com/docs for details." />
            </MemoryRouter>
        );
        const link = screen.getByRole('link', { name: 'https://example.com/docs' });
        expect(link).toHaveAttribute('href', 'https://example.com/docs');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('returns null for null content', () => {
        const { container } = render(
            <MemoryRouter>
                <HighlightedContent content={null} />
            </MemoryRouter>
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders rich text formatting including bold, inline code, checklists, and markdown links', () => {
        const markdown = `
### Task Summary
Here is **important info** with \`const id = 42\` and [FlowSync](https://flowsync.test).
- [ ] First todo item
- [x] Completed task
> Important note from team
`;
        render(
            <MemoryRouter>
                <HighlightedContent content={markdown} />
            </MemoryRouter>
        );

        expect(screen.getByText('Task Summary')).toBeInTheDocument();
        expect(screen.getByText('important info')).toBeInTheDocument();
        expect(screen.getByText('const id = 42')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'FlowSync' })).toHaveAttribute('href', 'https://flowsync.test');
        expect(screen.getByText('First todo item')).toBeInTheDocument();
        expect(screen.getByText('Completed task')).toBeInTheDocument();
        expect(screen.getByText('Important note from team')).toBeInTheDocument();
    });
});
