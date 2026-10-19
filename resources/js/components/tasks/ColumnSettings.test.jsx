import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ColumnSettingsDrawer from './ColumnSettingsDrawer';
import KanbanBoard from './KanbanBoard';

const statuses = [
    { id: 1, name: 'Todo', color: '#111111', wip_limit: null, tasks_count: 1, open_count: 1, tasks: [{ id: 10, key: 'A-1', title: 'First' }] },
    { id: 2, name: 'Doing', color: '#222222', wip_limit: 3, tasks_count: 0, open_count: 0, tasks: [] },
];
const prefs = { hidden: [], collapsed: [], width: 'default', density: 'comfortable', hide_empty: false };

function drawer(props = {}) {
    const onPrefs = vi.fn();
    render(
        <ColumnSettingsDrawer
            open
            onClose={() => {}}
            statuses={statuses}
            prefs={prefs}
            onPrefs={onPrefs}
            canManage={false}
            onEdit={() => {}}
            onMove={() => {}}
            onDelete={() => {}}
            onAdd={async () => true}
            {...props}
        />,
    );
    return onPrefs;
}

describe('ColumnSettingsDrawer', () => {
    it('lets anyone hide a column but only managers edit shared settings', () => {
        const onPrefs = drawer();
        fireEvent.click(screen.getByRole('switch', { name: /Show Doing/ }));
        expect(onPrefs).toHaveBeenCalledWith({ hidden: [2] });
        expect(screen.queryByRole('button', { name: /Delete Doing/ })).toBeNull();
        expect(screen.queryByText('Add a column')).toBeNull();
    });

    it('shows manage controls to managers', () => {
        drawer({ canManage: true });
        expect(screen.getByRole('button', { name: 'Edit Doing' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Move Todo up' }).disabled).toBe(true);
    });

    it('saves width and density preferences', () => {
        const onPrefs = drawer();
        fireEvent.click(screen.getByRole('radio', { name: 'Wide' }));
        expect(onPrefs).toHaveBeenCalledWith({ width: 'wide' });
        fireEvent.click(screen.getByRole('radio', { name: 'Compact' , exact: true }));
        expect(onPrefs).toHaveBeenCalledWith({ density: 'compact' });
    });
});

describe('KanbanBoard preferences', () => {
    const board = { statuses };

    it('omits hidden columns', () => {
        render(<KanbanBoard board={board} canMove={false} canEdit={false} onOpen={() => {}} onDragEnd={() => {}} prefs={{ ...prefs, hidden: [2], save: () => {} }} />);
        expect(screen.getByText('Todo')).toBeTruthy();
        expect(screen.queryByText('Doing')).toBeNull();
    });

    it('hides empty columns when asked', () => {
        render(<KanbanBoard board={board} canMove={false} canEdit={false} onOpen={() => {}} onDragEnd={() => {}} prefs={{ ...prefs, hide_empty: true, save: () => {} }} />);
        expect(screen.queryByText('Doing')).toBeNull();
    });
});
