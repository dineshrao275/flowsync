import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConfirmProvider } from '../../components/ui/ConfirmDialog';
import Signatures from './Signatures';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a) },
    fieldErrors: () => ({}),
}));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => false }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const row = { id: 5, status: 'pending', document: { title: 'NDA' }, signer: { name: 'Sam' }, can_sign: true, can_cancel: false, message: null };

describe('Signatures decline', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockResolvedValue({ data: { signatures: [row] } });
        post.mockResolvedValue({ data: {} });
    });

    it('asks for the decline reason in an in-app dialog, never window.prompt', async () => {
        const nativePrompt = vi.spyOn(window, 'prompt').mockReturnValue('nope');
        render(<ConfirmProvider><Signatures /></ConfirmProvider>);

        fireEvent.click(await screen.findByRole('button', { name: 'Decline' }));
        const dialog = await screen.findByRole('dialog');
        fireEvent.change(within(dialog).getByRole('textbox'), { target: { value: 'wrong terms' } });
        fireEvent.click(within(dialog).getByRole('button', { name: /submit|decline/i }));

        await vi.waitFor(() => expect(post).toHaveBeenCalledWith('/hrms/signatures/5/decline', { reason: 'wrong terms' }));
        expect(nativePrompt).not.toHaveBeenCalled();
    });
});
