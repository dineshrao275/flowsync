import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MySurvey from './MySurvey';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a) },
    fieldErrors: (err) => Object.fromEntries(Object.entries(err.response.data.errors ?? {}).map(([k, v]) => [k, v[0]])),
}));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const form = {
    campaign: { id: 5, name: 'Pulse', template: { is_anonymous: true } },
    answered: false,
    questions: [
        { id: 11, text: 'Rate us', type: 'scale', is_required: true },
        { id: 12, text: 'Any comment?', type: 'text', is_required: false },
    ],
};

const renderForm = () => render(
    <MemoryRouter initialEntries={['/hrms/engagement/mine/5']}>
        <Routes><Route path="/hrms/engagement/mine/:campaignId" element={<MySurvey />} /></Routes>
    </MemoryRouter>,
);

describe('MySurvey submit', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/my') ? { campaigns: [] } : form }));
    });

    it('returns to the unanswered required question and says what is missing', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { errors: { 'questions.11': ['That question needs an answer.'] } } } });
        renderForm();

        expect(await screen.findByText(/Question 1 of 2/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.change(screen.getByLabelText('Any comment?'), { target: { value: 'only a comment' } });
        fireEvent.click(screen.getByRole('button', { name: 'Submit answers' }));

        // The error belongs to question 1, so the form must go back to it rather than stay on question 2.
        await waitFor(() => expect(screen.getByText(/Question 1 of 2/)).toBeInTheDocument());
        expect(screen.getByText('That question needs an answer.')).toBeInTheDocument();
    });
});
