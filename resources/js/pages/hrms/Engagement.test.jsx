import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Engagement from './Engagement';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a) },
    fieldErrors: (err) => Object.fromEntries(Object.entries(err.response.data.errors ?? {}).map(([k, v]) => [k, v[0]])),
}));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => true }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const type = (label, value) => fireEvent.change(screen.getByLabelText(label), { target: { value } });
const submit = () => fireEvent.submit(screen.getByRole('button', { name: 'Create template' }).closest('form'));

describe('Engagement template form', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockResolvedValue({ data: { templates: [], campaigns: [] } });
    });

    it('shows a server error raised on a question, next to that question', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { errors: { 'questions.0.text': ['The question text is too long.'] } } } });
        render(<MemoryRouter><Engagement /></MemoryRouter>);

        fireEvent.click(await screen.findByRole('button', { name: 'New template' }));
        type(/^Name/, 'Pulse');
        type(/^Question 1/, 'Something');
        submit();

        await waitFor(() => expect(screen.getByText('The question text is too long.')).toBeInTheDocument());
    });

    it('maps the error to the right question when a blank question before it was dropped', async () => {
        // Questions 1 and 3 are sent (indexes 0 and 1); question 2 is blank and filtered out.
        post.mockRejectedValue({ response: { status: 422, data: { errors: { 'questions.1.text': ['Third question is bad.'] } } } });
        render(<MemoryRouter><Engagement /></MemoryRouter>);

        fireEvent.click(await screen.findByRole('button', { name: 'New template' }));
        type(/^Name/, 'Pulse');
        type(/^Question 1/, 'First');
        fireEvent.click(screen.getByRole('button', { name: 'Add a question' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add a question' }));
        type(/^Question 3/, 'Third');
        submit();

        const message = await screen.findByText('Third question is bad.');
        expect(message.closest('div.rounded-lg')).toContainElement(screen.getByLabelText(/^Question 3/));
    });

    it('shows a form-level error that belongs to no field', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { errors: { questions: ['A survey needs at least one question.'] } } } });
        render(<MemoryRouter><Engagement /></MemoryRouter>);

        fireEvent.click(await screen.findByRole('button', { name: 'New template' }));
        type(/^Name/, 'Pulse');
        type(/^Question 1/, 'Something');
        submit();

        await waitFor(() => expect(screen.getByText('A survey needs at least one question.')).toBeInTheDocument());
    });
});
