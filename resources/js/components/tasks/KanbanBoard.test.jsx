import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import KanbanBoard from './KanbanBoard';

const board = {
    statuses: [
        { id: 1, name: 'To do', color: '#94a3b8', tasks_count: 1, open_count: 1, wip_limit: null, tasks: [{ id: 10, key: 'P-1', title: 'First', labels: [], components: [] }] },
        { id: 2, name: 'Done', color: '#10b981', tasks_count: 0, open_count: 0, wip_limit: null, tasks: [] },
    ],
};

describe('KanbanBoard', () => {
    beforeEach(() => window.localStorage.clear());

    function renderBoard(extra = {}) {
        return render(<KanbanBoard board={board} canMove canEdit canCreate onOpen={() => {}} onDragEnd={() => {}} onQuickAdd={vi.fn().mockResolvedValue()} {...extra} />);
    }

    it('collapses and re-expands a column, remembering the choice', () => {
        renderBoard();

        fireEvent.click(screen.getByRole('button', { name: 'Collapse column To do' }));
        expect(screen.queryByText('First')).not.toBeInTheDocument();
        expect(JSON.parse(window.localStorage.getItem('flowsync.board.collapsed'))).toEqual([1]);

        fireEvent.click(screen.getByRole('button', { name: 'Expand column To do' }));
        expect(screen.getByText('First')).toBeInTheDocument();
    });

    it('quick-adds a task into the chosen column and clears the field', async () => {
        const onQuickAdd = vi.fn().mockResolvedValue();
        renderBoard({ onQuickAdd });

        fireEvent.click(screen.getAllByRole('button', { name: /Add task/ })[1]);
        const input = screen.getByLabelText('New task title in Done');
        fireEvent.change(input, { target: { value: '  Ship it ' } });
        fireEvent.submit(input.closest('form'));

        await waitFor(() => expect(onQuickAdd).toHaveBeenCalledWith(2, 'Ship it'));
        await waitFor(() => expect(input).toHaveValue(''));
    });

    it('keeps the typed title when the add fails', async () => {
        const onQuickAdd = vi.fn().mockRejectedValue(new Error('422'));
        renderBoard({ onQuickAdd });

        fireEvent.click(screen.getAllByRole('button', { name: /Add task/ })[0]);
        const input = screen.getByLabelText('New task title in To do');
        fireEvent.change(input, { target: { value: 'Keep me' } });
        fireEvent.submit(input.closest('form'));

        await waitFor(() => expect(onQuickAdd).toHaveBeenCalled());
        expect(input).toHaveValue('Keep me');
    });

    it('hides quick-add without create permission', () => {
        renderBoard({ canCreate: false });
        expect(screen.queryByRole('button', { name: /Add task/ })).not.toBeInTheDocument();
    });
});
