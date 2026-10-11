import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ConfirmProvider, useConfirm } from './ConfirmDialog';

function Harness({ options, onResult }) {
    const confirm = useConfirm();

    return (
        <button
            type="button"
            onClick={async () => {
                onResult(await confirm(options));
            }}
        >
            trigger
        </button>
    );
}

function mount(options = {}) {
    const results = [];
    render(
        <ConfirmProvider>
            <Harness options={options} onResult={(value) => results.push(value)} />
        </ConfirmProvider>,
    );
    return results;
}

describe('ConfirmDialog / useConfirm', () => {
    it('shows the prompt and resolves true when confirmed', async () => {
        const results = mount({ title: 'Delete plan?', message: 'Really?', confirmLabel: 'Yes, delete' });

        fireEvent.click(screen.getByText('trigger'));

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toBeInTheDocument();
        expect(screen.getByText('Delete plan?')).toBeInTheDocument();
        expect(screen.getByText('Really?')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Yes, delete'));

        await waitFor(() => expect(results).toEqual([true]));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('resolves false when cancelled and closes the dialog', async () => {
        const results = mount({ message: 'Discard changes?' });

        fireEvent.click(screen.getByText('trigger'));
        await screen.findByRole('dialog');

        fireEvent.click(screen.getByText('Cancel'));

        await waitFor(() => expect(results).toEqual([false]));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('defaults the confirm label to Delete for a danger prompt', async () => {
        const results = mount({ message: 'Delete this?', variant: 'danger' });

        fireEvent.click(screen.getByText('trigger'));
        await screen.findByRole('dialog');

        fireEvent.click(screen.getByText('Delete'));

        await waitFor(() => expect(results).toEqual([true]));
    });

    it('throws when useConfirm is used outside a ConfirmProvider', () => {
        function Bare() {
            useConfirm();
            return null;
        }

        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

        expect(() => render(<Bare />)).toThrow(/ConfirmProvider/);

        spy.mockRestore();
    });
});
