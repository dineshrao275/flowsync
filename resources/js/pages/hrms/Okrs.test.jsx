import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Okrs from './Okrs';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a), put: vi.fn() },
    fieldErrors: (err) => Object.fromEntries(Object.entries(err.response.data.errors ?? {}).map(([k, v]) => [k, v[0]])),
}));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const fillAndSubmit = async () => {
    fireEvent.click(await screen.findByRole('button', { name: 'New objective' }));
    fireEvent.change(screen.getByLabelText(/^Objective/), { target: { value: 'Grow' } });
    fireEvent.change(screen.getByLabelText(/^Period/), { target: { value: 'Q1' } });
    fireEvent.change(screen.getByLabelText(/^Starts/), { target: { value: '2026-06-01' } });
    fireEvent.change(screen.getByLabelText(/^Ends/), { target: { value: '2026-01-01' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Create objective' }).closest('form'));
};

describe('Okrs new-objective form', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockResolvedValue({ data: { objectives: [] } });
    });

    it('shows a field validation error once, not again in a generic banner', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { message: 'The end date must be after start date.', errors: { end_date: ['The end date must be after start date.'] } } } });
        render(<Okrs />);
        await fillAndSubmit();

        await waitFor(() => expect(screen.getAllByText('The end date must be after start date.').length).toBeGreaterThan(0));
        expect(screen.getAllByText('The end date must be after start date.')).toHaveLength(1);
    });

    it('still surfaces the scope error (it has no field of its own)', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { message: 'x', errors: { scope: ['You need an employment record to own an objective.'] } } } });
        render(<Okrs />);
        await fillAndSubmit();

        expect(await screen.findAllByText('You need an employment record to own an objective.')).toHaveLength(1);
    });
});
