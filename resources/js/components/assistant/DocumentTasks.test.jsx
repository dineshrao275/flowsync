import { act, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ProposalReview from './ProposalReview';
import useDocumentTasks from '../../hooks/useDocumentTasks';
import api from '../../services/api';

vi.mock('../../services/api', () => ({
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
    fieldErrors: (error) => error?.response?.data?.errors || {},
}));

const proposal = (over = {}) => ({
    id: 'p-1',
    project_id: 3,
    truncated: false,
    priorities: [{ slug: 'high', name: 'High' }, { slug: 'low', name: 'Low' }],
    items: [
        { id: 'a', title: 'Build login', description: 'Do it', priority: 'high', story_points: 3, selected: true, duplicate_of: null, result: null },
        { id: 'b', title: 'Audit log', description: '', priority: null, story_points: null, selected: false, duplicate_of: 'DOC-9', result: null },
    ],
    ...over,
});

beforeEach(() => vi.clearAllMocks());

describe('ProposalReview', () => {
    it('shows duplicate hints and lets the reviewer edit, untick and remove', () => {
        const onEdit = vi.fn();
        const onRemove = vi.fn();
        render(<MemoryRouter><ProposalReview proposal={proposal()} items={proposal().items} onEdit={onEdit} onRemove={onRemove} onAdd={vi.fn()} /></MemoryRouter>);

        expect(screen.getByText('Similar to DOC-9')).toBeInTheDocument();
        expect(screen.getByText('1 task will be created')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Title of task 1'), { target: { value: 'Build the login screen' } });
        expect(onEdit).toHaveBeenCalledWith('a', { title: 'Build the login screen' });
        fireEvent.click(screen.getByLabelText('Include task 2'));
        expect(onEdit).toHaveBeenCalledWith('b', { selected: true });
        fireEvent.click(screen.getByLabelText('Remove task 1'));
        expect(onRemove).toHaveBeenCalledWith('a');
    });

    it('locks created rows, links them, and explains a failed row', () => {
        const items = [
            { ...proposal().items[0], result: { status: 'created', key: 'DOC-1', link: '/projects/3?tab=tasks&task=DOC-1' } },
            { ...proposal().items[1], selected: true, result: { status: 'failed', error: 'Plan limit reached' } },
        ];
        render(<MemoryRouter><ProposalReview proposal={proposal()} items={items} onEdit={vi.fn()} onRemove={vi.fn()} onAdd={vi.fn()} /></MemoryRouter>);

        expect(screen.getByLabelText('Title of task 1')).toBeDisabled();
        expect(screen.getByRole('link', { name: 'Created DOC-1' })).toHaveAttribute('href', '/projects/3?tab=tasks&task=DOC-1');
        expect(screen.getByText(/Plan limit reached/)).toBeInTheDocument();
        expect(screen.getByLabelText('Title of task 2')).not.toBeDisabled();
    });
});

describe('useDocumentTasks', () => {
    it('drafts without creating anything, then saves edits before confirming', async () => {
        api.post.mockImplementation((url) => {
            if (url === '/ai/proposals') return Promise.resolve({ data: { proposal: proposal() } });
            if (url === '/ai/proposals/p-1/confirm') return Promise.resolve({ data: { proposal: proposal({ status: 'confirmed', items: proposal().items.map((i) => ({ ...i, result: { status: 'created', key: 'DOC-1', link: '/x' } })) }) } });
            return Promise.resolve({ data: {} });
        });
        api.put.mockResolvedValue({ data: {} });
        const { result } = renderHook(() => useDocumentTasks());

        await act(() => result.current.generate({ projectId: '3', file: null, text: 'Users log in', instructions: '' }));

        expect(result.current.step).toBe('review');
        expect(api.post).toHaveBeenCalledTimes(1); // only the draft; no confirm yet
        expect(api.post.mock.calls[0][1]).toMatchObject({ project_id: 3, text: 'Users log in' });

        act(() => result.current.edit('a', { title: 'Edited' }));
        act(() => result.current.add());
        await act(() => result.current.confirm());

        const sent = api.put.mock.calls[0][1].items;
        expect(sent[0]).toMatchObject({ id: 'a', title: 'Edited' });
        expect(sent[2].id).toBeUndefined(); // a client-added row has no server id yet
        expect(api.post.mock.calls.at(-1)[0]).toBe('/ai/proposals/p-1/confirm');
        expect(result.current.step).toBe('done');
    });

    it('uploads the file first and sends its id, not the text', async () => {
        api.post.mockImplementation((url) => Promise.resolve({ data: url === '/ai/documents' ? { document: { id: 12 } } : { proposal: proposal() } }));
        const { result } = renderHook(() => useDocumentTasks());

        await act(() => result.current.generate({ projectId: '3', file: new File(['x'], 'r.txt'), text: 'ignored', instructions: 'backend only' }));

        expect(api.post.mock.calls[0][0]).toBe('/ai/documents');
        expect(api.post.mock.calls[1][1]).toMatchObject({ document_id: 12, text: null, instructions: 'backend only' });
    });

    it('returns to the form with the server message when drafting fails', async () => {
        api.post.mockRejectedValue({ response: { status: 502, data: { message: 'The AI assistant could not answer.', code: 'ai_error' } } });
        const { result } = renderHook(() => useDocumentTasks());

        await act(() => result.current.generate({ projectId: '3', file: null, text: 'x', instructions: '' }));

        await waitFor(() => expect(result.current.step).toBe('input'));
        expect(result.current.error.code).toBe('ai_error');
    });

    it('stays on review with the error when confirming is refused', async () => {
        api.post.mockImplementation((url) => (url === '/ai/proposals' ? Promise.resolve({ data: { proposal: proposal() } }) : Promise.reject({ response: { status: 403, data: { message: 'Forbidden' } } })));
        api.put.mockResolvedValue({ data: {} });
        const { result } = renderHook(() => useDocumentTasks());
        await act(() => result.current.generate({ projectId: '3', file: null, text: 'x', instructions: '' }));

        await act(() => result.current.confirm());

        expect(result.current.step).toBe('review');
        expect(result.current.error.code).toBe('http_403');
    });
});
