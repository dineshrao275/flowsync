import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import api from '../../services/api';
import CommentThread from './CommentThread';
import { ConfirmProvider } from '../ui/ConfirmDialog';

vi.mock('../../context/AuthContext', () => ({
    useAuth: () => ({ user: { id: 1, name: 'Current User' } }),
}));

vi.mock('../../context/ToastContext', () => ({
    useToast: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}));

describe('CommentThread', () => {
    const task = { id: 10, project_id: 5, key: 'PROJ-10' };
    const mockComments = [
        {
            id: 101,
            comment: 'First comment on task',
            created_at: '2026-06-01T12:00:00Z',
            user: { id: 2, name: 'Sofia Joshi' },
            replies: [],
        },
    ];

    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('loads and renders comments', async () => {
        vi.spyOn(api, 'get').mockImplementation((url) => {
            if (url.includes('/comments')) {
                return Promise.resolve({ data: { comments: mockComments } });
            }
            return Promise.resolve({ data: {} });
        });

        render(
            <ConfirmProvider>
                <CommentThread task={task} projectId={5} isManager={true} />
            </ConfirmProvider>,
        );

        expect(await screen.findByText('First comment on task')).toBeInTheDocument();
        expect(screen.getByText('Sofia Joshi')).toBeInTheDocument();
    });

    it('triggers autocomplete on @ and portalled suggestions insert mention on click', async () => {
        vi.spyOn(api, 'get').mockImplementation((url) => {
            if (url.includes('/comments')) {
                return Promise.resolve({ data: { comments: mockComments } });
            }
            if (url.includes('/members/autocomplete')) {
                return Promise.resolve({
                    data: {
                        members: [
                            { id: 42, name: 'Adrian Lopez', email: 'adrian@flowsync.test' },
                        ],
                    },
                });
            }
            return Promise.resolve({ data: {} });
        });

        const postSpy = vi.spyOn(api, 'post').mockResolvedValue({ data: { comment: {} } });

        render(
            <ConfirmProvider>
                <CommentThread task={task} projectId={5} isManager={true} />
            </ConfirmProvider>,
        );

        await screen.findByText('First comment on task');

        const composer = screen.getByPlaceholderText(/add a comment/i);
        fireEvent.change(composer, {
            target: { value: 'Hey @', selectionStart: 5 },
        });

        await waitFor(() => {
            expect(api.get).toHaveBeenCalledWith('/projects/5/members/autocomplete', {
                params: { q: '' },
            });
        });

        const memberOption = await screen.findByText('Adrian Lopez');
        expect(memberOption).toBeInTheDocument();

        fireEvent.click(memberOption);

        expect(composer.value).toBe('Hey @Adrian Lopez ');

        const postBtn = screen.getByRole('button', { name: /^post/i });
        fireEvent.click(postBtn);

        await waitFor(() => {
            expect(postSpy).toHaveBeenCalledWith('/projects/5/tasks/10/comments', {
                comment: 'Hey @Adrian Lopez',
            });
        });
    });

    it('navigates autocomplete suggestions with keyboard arrows and Enter', async () => {
        vi.spyOn(api, 'get').mockImplementation((url) => {
            if (url.includes('/comments')) {
                return Promise.resolve({ data: { comments: mockComments } });
            }
            if (url.includes('/members/autocomplete')) {
                return Promise.resolve({
                    data: {
                        members: [
                            { id: 42, name: 'Adrian Lopez', email: 'adrian@flowsync.test' },
                            { id: 43, name: 'Chloe Alvarez', email: 'chloe@flowsync.test' },
                        ],
                    },
                });
            }
            return Promise.resolve({ data: {} });
        });

        render(
            <ConfirmProvider>
                <CommentThread task={task} projectId={5} isManager={true} />
            </ConfirmProvider>,
        );

        await screen.findByText('First comment on task');

        const composer = screen.getByPlaceholderText(/add a comment/i);
        fireEvent.change(composer, {
            target: { value: '@', selectionStart: 1 },
        });

        await screen.findByText('Chloe Alvarez');

        // Press ArrowDown to select Chloe Alvarez (index 1)
        fireEvent.keyDown(composer, { key: 'ArrowDown' });
        // Press Enter to choose
        fireEvent.keyDown(composer, { key: 'Enter' });

        expect(composer.value).toBe('@Chloe Alvarez ');
        expect(screen.queryByText('adrian@flowsync.test')).not.toBeInTheDocument();
    });
});
