import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import TaskBadge from './TaskBadge';

describe('TaskBadge component', () => {
    it('renders task key with hash prefix', () => {
        render(
            <MemoryRouter>
                <TaskBadge taskKey="TASK-123" />
            </MemoryRouter>
        );
        expect(screen.getByText('TASK-123')).toBeInTheDocument();
    });

    it('generates project task deep link when projectId is provided', () => {
        render(
            <MemoryRouter>
                <TaskBadge taskKey="TASK-123" projectId={5} />
            </MemoryRouter>
        );
        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', '/projects/5?tab=tasks&task=TASK-123');
    });

    it('generates search link when projectId is not provided', () => {
        render(
            <MemoryRouter>
                <TaskBadge taskKey="PROJ-99" />
            </MemoryRouter>
        );
        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', '/search?q=PROJ-99');
    });

    it('returns null if neither taskKey nor taskId is given', () => {
        const { container } = render(
            <MemoryRouter>
                <TaskBadge />
            </MemoryRouter>
        );
        expect(container.firstChild).toBeNull();
    });
});
