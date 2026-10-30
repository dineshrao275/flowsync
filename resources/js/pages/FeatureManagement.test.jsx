import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import FeatureManagement from './FeatureManagement';
import { BreadcrumbProvider } from '../context/BreadcrumbContext';

const get = vi.fn();
const put = vi.fn();
const toast = { success: vi.fn(), error: vi.fn() };

vi.mock('../services/api', () => ({ default: { get: (...a) => get(...a), put: (...a) => put(...a) }, fieldErrors: () => ({}) }));
vi.mock('../context/ToastContext', () => ({ useToast: () => toast }));

const payload = {
    plans: [{ id: 1, name: 'Starter', is_active: true, modules: ['reports'] }, { id: 2, name: 'Pro', is_active: false, modules: [] }],
    groups: [{ key: 'tms', label: 'Task management', modules: [{ key: 'reports', label: 'Reports', depth: 1 }] }],
};

const renderPage = () => render(<MemoryRouter><BreadcrumbProvider><FeatureManagement /></BreadcrumbProvider></MemoryRouter>);

describe('FeatureManagement page', () => {
    beforeEach(() => { get.mockReset(); put.mockReset(); toast.success.mockReset(); toast.error.mockReset(); });

    it('renders the module grid and flips a toggle through the API', async () => {
        get.mockResolvedValue({ data: payload });
        put.mockResolvedValue({ data: { message: 'Updated.', plan: { modules: [] } } });
        renderPage();

        expect(await screen.findByText('Task management')).toBeInTheDocument();
        const switches = screen.getAllByRole('button');
        fireEvent.click(switches[0]);

        await vi.waitFor(() => expect(put).toHaveBeenCalledWith('/system/features/1', { module: 'reports', enabled: false }));
        await vi.waitFor(() => expect(toast.success).toHaveBeenCalledWith('Updated.'));
    });

    it('offers a retry when the catalogue fails to load', async () => {
        get.mockRejectedValueOnce(new Error('boom')).mockResolvedValue({ data: payload });
        renderPage();

        fireEvent.click(await screen.findByRole('button', { name: /try again/i }));

        expect(await screen.findByText('Task management')).toBeInTheDocument();
    });
});
