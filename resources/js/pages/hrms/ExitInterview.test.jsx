import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExitInterview from './ExitInterview';

const get = vi.fn();
const put = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), put: (...a) => put(...a) },
    fieldErrors: (err) => {
        const p = err?.response?.data;
        if (p?.errors) return Object.fromEntries(Object.entries(p.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
        return { form: p?.message ?? 'generic' };
    },
}));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const questions = [{ key: 'primary_reason', type: 'choice', label: 'Why?', required: true, options: ['other'] }];

describe('ExitInterview', () => {
    beforeEach(() => {
        get.mockReset();
        put.mockReset();
        get.mockResolvedValue({ data: { interview: { status: 'pending', answers: {} }, questions } });
    });

    it('shows a form-level server error instead of failing silently', async () => {
        put.mockRejectedValue({ response: { status: 404, data: { message: 'You have no exit in progress.' } } });
        render(<ExitInterview />);

        fireEvent.click(await screen.findByRole('button', { name: 'Submit exit interview' }));

        expect(await screen.findByText('You have no exit in progress.')).toBeInTheDocument();
    });

    it('shows the per-question error under the question', async () => {
        put.mockRejectedValue({ response: { status: 422, data: { errors: { 'answers.primary_reason': ['This question needs an answer.'] } } } });
        render(<ExitInterview />);

        fireEvent.click(await screen.findByRole('button', { name: 'Submit exit interview' }));

        expect(await screen.findByText('This question needs an answer.')).toBeInTheDocument();
    });
});
