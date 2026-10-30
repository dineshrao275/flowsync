import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminActivity from './AdminActivity';
import { BreadcrumbProvider } from '../context/BreadcrumbContext';

const get = vi.fn();

vi.mock('../services/api', () => ({ default: { get: (...a) => get(...a) }, fieldErrors: () => ({}) }));

const payload = {
    summary: { tenants: 3, with_data: 2, dormant: 1, active_users_30d: 9 },
    tenants: [
        { id: 1, name: 'Acme', slug: 'acme', users: 5, active_7d: 3, active_30d: 4, activity_30d: 20, dormant: false, as_of: '2026-10-01' },
        { id: 2, name: 'Globex', slug: 'globex', users: null, active_7d: null, active_30d: null, activity_30d: null, dormant: true, as_of: null },
    ],
};

const renderPage = () => render(<MemoryRouter><BreadcrumbProvider><AdminActivity /></BreadcrumbProvider></MemoryRouter>);

describe('AdminActivity page', () => {
    beforeEach(() => get.mockReset());

    it('shows the summary and one row per tenant, with em dashes for missing numbers', async () => {
        get.mockResolvedValue({ data: payload });
        renderPage();

        expect(await screen.findByText('Acme')).toBeInTheDocument();
        expect(screen.getByText('Globex')).toBeInTheDocument();
        expect(screen.getByText('9')).toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(4);
        expect(get).toHaveBeenCalledWith('/system/tenant-activity', expect.objectContaining({ signal: expect.anything() }));
    });

    it('offers a retry when the load fails, and recovers on success', async () => {
        get.mockRejectedValueOnce(new Error('boom')).mockResolvedValue({ data: payload });
        renderPage();

        const retry = await screen.findByRole('button', { name: /try again/i });
        fireEvent.click(retry);

        expect(await screen.findByText('Acme')).toBeInTheDocument();
        expect(get).toHaveBeenCalledTimes(2);
    });
});
