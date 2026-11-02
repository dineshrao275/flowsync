import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AssetCategoryQuickAdd from './AssetCategoryQuickAdd';

const post = vi.fn();
const success = vi.fn();

vi.mock('../../services/api', () => ({
    default: { post: (...a) => post(...a) },
    fieldErrors: (err) => err?.response?.data?.errors ?? {},
}));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success, error: vi.fn() }) }));

describe('AssetCategoryQuickAdd', () => {
    beforeEach(() => { post.mockReset(); success.mockReset(); });

    it('keeps Add disabled until a name is typed, then creates the category and reports it', async () => {
        post.mockResolvedValue({ data: { category: { id: 7, name: 'Laptops' } } });
        const onCreated = vi.fn();
        render(<AssetCategoryQuickAdd onCreated={onCreated} empty />);

        expect(screen.getByText(/No asset categories yet/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add category' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('New category'), { target: { value: '  Laptops ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add category' }));

        await waitFor(() => expect(onCreated).toHaveBeenCalledWith({ id: 7, name: 'Laptops' }));
        expect(post).toHaveBeenCalledWith('/hrms/assets/categories', { name: 'Laptops' });
        expect(success).toHaveBeenCalled();
    });

    it('shows the server validation error and does not report a category', async () => {
        post.mockRejectedValue({ response: { status: 422, data: { errors: { name: 'The name field is required.' } } } });
        const onCreated = vi.fn();
        render(<AssetCategoryQuickAdd onCreated={onCreated} />);

        fireEvent.change(screen.getByLabelText('New category'), { target: { value: 'x' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add category' }));

        expect(await screen.findByText('The name field is required.')).toBeInTheDocument();
        expect(onCreated).not.toHaveBeenCalled();
    });
});
