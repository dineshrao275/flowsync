import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import OrgChart from './OrgChart';

const get = vi.fn();

vi.mock('../../services/api', () => ({ default: { get: (...a) => get(...a) }, fieldErrors: () => ({}) }));

const nodes = [
    { id: 1, name: 'Ada Boss', title: 'CEO', department: 'Exec', manager_id: null },
    { id: 2, name: 'Bob Report', title: 'Engineer', department: 'Eng', manager_id: 1 },
];

const renderPage = () => render(<MemoryRouter><OrgChart /></MemoryRouter>);

describe('OrgChart page', () => {
    beforeEach(() => get.mockReset());

    it('renders the reporting tree from the response', async () => {
        get.mockResolvedValue({ data: { nodes, truncated: false } });
        renderPage();

        expect(await screen.findByText('Ada Boss')).toBeInTheDocument();
        expect(screen.getByText('Bob Report')).toBeInTheDocument();
        expect(get).toHaveBeenCalledWith('/hrms/org-chart', expect.objectContaining({ signal: expect.anything() }));
    });

    it('shows the empty state when nobody is on the chart', async () => {
        get.mockResolvedValue({ data: { nodes: [], truncated: false } });
        renderPage();

        expect(await screen.findByText('No active employees yet.')).toBeInTheDocument();
    });

    it('retries after a failed load', async () => {
        get.mockRejectedValueOnce(new Error('boom')).mockResolvedValue({ data: { nodes, truncated: false } });
        renderPage();

        fireEvent.click(await screen.findByRole('button', { name: /try again/i }));

        expect(await screen.findByText('Ada Boss')).toBeInTheDocument();
    });
});
