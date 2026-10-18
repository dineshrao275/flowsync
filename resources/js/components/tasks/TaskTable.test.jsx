import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import TaskTable from './TaskTable';

const sampleTasks = [
    {
        id: 1,
        key: 'PROJ-1',
        title: 'Fix assignee user chip',
        status: { name: 'In Progress', color: '#3b82f6' },
        priority: { name: 'High' },
        story_points: 3,
        assignee: { id: 10, name: 'Alice Smith' },
        due_date: '2026-10-20',
        labels: [],
        components: [],
    },
    {
        id: 2,
        key: 'PROJ-2',
        title: 'Unassigned task',
        status: { name: 'To Do', color: '#94a3b8' },
        priority: { name: 'Medium' },
        story_points: null,
        assignee: null,
        due_date: null,
        labels: [],
        components: [],
    },
];

describe('TaskTable', () => {
    it('renders task listing table with assignee chips without throwing', () => {
        const onOpen = vi.fn();
        render(<TaskTable tasks={sampleTasks} canEdit={true} onOpen={onOpen} />);

        expect(screen.getByText('PROJ-1')).toBeInTheDocument();
        expect(screen.getByText('Fix assignee user chip')).toBeInTheDocument();
        expect(screen.getByText('Alice Smith')).toBeInTheDocument();
        expect(screen.getByText('PROJ-2')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Fix assignee user chip'));
        expect(onOpen).toHaveBeenCalledWith(sampleTasks[0]);
    });

    it('renders empty placeholder when no tasks provided', () => {
        render(<TaskTable tasks={[]} canEdit={false} onOpen={() => {}} />);
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
});
