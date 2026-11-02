import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConfirmProvider } from '../../components/ui/ConfirmDialog';
import Letters from './Letters';

const get = vi.fn();
const del = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), delete: (...a) => del(...a), post: vi.fn(), put: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const template = { id: 3, name: 'Offer letter', document_type_slug: 'offer', body: 'Hi', is_active: true };

describe('Letters delete', () => {
    beforeEach(() => {
        get.mockReset();
        del.mockReset();
        get.mockImplementation((url) => Promise.resolve({ data: url.includes('letter-templates') ? { templates: [template], fields: ['employee_name'] } : { employees: [] } }));
        del.mockResolvedValue({ data: {} });
    });

    it('asks before deleting a template, and a cancel keeps it', async () => {
        render(<ConfirmProvider><Letters /></ConfirmProvider>);

        fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));
        const dialog = await screen.findByRole('dialog');
        expect(del).not.toHaveBeenCalled();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(del).not.toHaveBeenCalled();
    });

    it('deletes after the user confirms', async () => {
        render(<ConfirmProvider><Letters /></ConfirmProvider>);

        fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));
        const dialog = await screen.findByRole('dialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }));

        await vi.waitFor(() => expect(del).toHaveBeenCalledWith('/hrms/letter-templates/3'));
    });
});
