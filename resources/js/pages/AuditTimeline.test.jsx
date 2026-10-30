import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AuditTimeline from './AuditTimeline';

const get = vi.fn();

vi.mock('../services/api', () => ({ default: { get: (...a) => get(...a), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, fieldErrors: () => ({}) }));

vi.mock('../components/ui/ListSavedFilters', () => ({ default: () => null }));

const page = {
    sources: ['activity', 'hrms'],
    entries: [{ source: 'hrms', id: 7, action: 'leave.approved', subject_type: 'leave', subject_id: 3, created_at: '2026-10-01T10:00:00Z', actor: 'Ada', ip_address: '1.2.3.4' }],
    pagination: { current_page: 1, last_page: 1, total: 1 },
};

const timelineCalls = () => get.mock.calls.filter(([url]) => url === '/audit/timeline');

function mockApi(timeline) {
    get.mockImplementation((url, opts) => (url === '/audit/timeline' ? timeline(opts) : Promise.resolve({ data: { filters: [] } })));
}

const renderPage = () => render(<MemoryRouter><AuditTimeline /></MemoryRouter>);

describe('AuditTimeline page', () => {
    beforeEach(() => get.mockReset());

    it('lists entries with their source, action and actor', async () => {
        mockApi(() => Promise.resolve({ data: page }));
        renderPage();

        expect(await screen.findByText('leave.approved')).toBeInTheDocument();
        expect(screen.getByText(/Ada/)).toBeInTheDocument();
        expect(timelineCalls()[0][1].signal).toBeDefined();
    });

    it('refetches with the filter when it changes', async () => {
        mockApi(() => Promise.resolve({ data: page }));
        renderPage();
        await screen.findByText('leave.approved');

        fireEvent.change(screen.getByLabelText('Action contains'), { target: { value: 'leave' } });

        await vi.waitFor(() => expect(timelineCalls().some(([, o]) => o.params.action === 'leave')).toBe(true));
    });

    it('shows a retry when the first load fails', async () => {
        let calls = 0;
        mockApi(() => (++calls === 1 ? Promise.reject(new Error('boom')) : Promise.resolve({ data: page })));
        renderPage();

        fireEvent.click(await screen.findByRole('button', { name: /try again/i }));

        expect(await screen.findByText('leave.approved')).toBeInTheDocument();
    });
});
