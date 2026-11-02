import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DevelopmentPlans from './DevelopmentPlans';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a), put: vi.fn() },
    fieldErrors: (err) => Object.fromEntries(Object.entries(err.response.data.errors ?? {}).map(([k, v]) => [k, v[0]])),
}));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

describe('DevelopmentPlans start form', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockResolvedValue({ data: { plans: [] } });
    });

    it('shows a field validation error once, not again in a generic banner', async () => {
        post.mockRejectedValue({
            response: { status: 422, data: { message: 'The plan must end after it starts.', errors: { end_date: ['The plan must end after it starts.'] } } },
        });
        render(<DevelopmentPlans />);

        fireEvent.click(await screen.findByRole('button', { name: 'Start a plan' }));
        fireEvent.change(screen.getByLabelText(/^Goal/), { target: { value: 'Grow' } });
        fireEvent.change(screen.getByLabelText(/^Starts/), { target: { value: '2026-06-01' } });
        fireEvent.change(screen.getByLabelText(/^Ends/), { target: { value: '2026-01-01' } });
        fireEvent.submit(screen.getByRole('button', { name: 'Start plan' }).closest('form'));

        await waitFor(() => expect(screen.getAllByText('The plan must end after it starts.').length).toBeGreaterThan(0));
        expect(screen.getAllByText('The plan must end after it starts.')).toHaveLength(1);
    });

    it('still shows a message that belongs to no field (abort-style error)', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { message: 'You need an employment record to have a development plan.' } } });
        render(<DevelopmentPlans />);

        fireEvent.click(await screen.findByRole('button', { name: 'Start a plan' }));
        fireEvent.change(screen.getByLabelText(/^Goal/), { target: { value: 'Grow' } });
        fireEvent.change(screen.getByLabelText(/^Starts/), { target: { value: '2026-01-01' } });
        fireEvent.change(screen.getByLabelText(/^Ends/), { target: { value: '2026-02-01' } });
        fireEvent.submit(screen.getByRole('button', { name: 'Start plan' }).closest('form'));

        expect(await screen.findAllByText('You need an employment record to have a development plan.')).toHaveLength(1);
    });
});
