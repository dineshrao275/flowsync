import { describe, expect, it } from 'vitest';
import { refreshSelected } from './selectedTask';

describe('refreshSelected', () => {
    it('updates the open task with its fresh copy', () => {
        expect(refreshSelected({ id: 1, title: 'old' }, { id: 1, title: 'new' })).toEqual({ id: 1, title: 'new' });
    });

    it('does not re-open a tray that was closed while the refetch was in flight', () => {
        expect(refreshSelected(null, { id: 1, title: 'new' })).toBeNull();
    });

    it('does not replace a different task the user has since opened', () => {
        expect(refreshSelected({ id: 2, title: 'other' }, { id: 1, title: 'new' })).toEqual({ id: 2, title: 'other' });
    });
});
