import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Careers from './Careers';

const get = vi.fn();
const post = vi.fn();
let allowed = false;

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a), delete: vi.fn(), put: vi.fn() },
    fieldErrors: (err) => err?.response?.data?.errors ? Object.fromEntries(Object.entries(err.response.data.errors).map(([k, v]) => [k, v[0]])) : { form: 'generic' },
}));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => allowed }) }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const render_ = () => render(<MemoryRouter><Careers /></MemoryRouter>);

describe('Careers data loading', () => {
    beforeEach(() => {
        get.mockReset();
        get.mockImplementation((url) => Promise.resolve({ data: url.includes('career-paths') ? { paths: [] } : { filters: { designations: [] }, employees: [] } }));
    });

    it('does not request the employee directory for someone who cannot manage talent (it would 403)', async () => {
        allowed = false;
        render_();

        expect(await screen.findByText('No career paths defined yet.')).toBeInTheDocument();
        expect(get.mock.calls.map((c) => c[0])).not.toContain('/hrms/employees');
    });

    it('loads designations and people for a talent manager', async () => {
        allowed = true;
        render_();

        await screen.findByText('No career paths defined yet.');
        expect(get.mock.calls.map((c) => c[0])).toContain('/hrms/employees');
    });

    it('shows the server error when a key position cannot be saved instead of failing silently', async () => {
        allowed = true;
        get.mockImplementation((url) => Promise.resolve({ data: url.includes('succession') ? { positions: [] } : url.includes('career-paths') ? { paths: [] } : { filters: { designations: [] }, employees: [] } }));
        post.mockRejectedValue({ response: { status: 422, data: { errors: { title: ['The title may not be greater than 255 characters.'] } } } });
        render_();

        fireEvent.click(await screen.findByRole('tab', { name: 'Succession' }));
        fireEvent.change(await screen.findByLabelText(/Key position/), { target: { value: 'x'.repeat(300) } });
        fireEvent.click(screen.getByRole('button', { name: 'Add position' }));

        expect(await screen.findByText('The title may not be greater than 255 characters.')).toBeInTheDocument();
    });
});
