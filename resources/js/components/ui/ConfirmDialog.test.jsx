import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ConfirmProvider, useAlert, useConfirm, usePrompt } from './ConfirmDialog';

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

function PromptHarness({ options, onResult }) {
    const prompt = usePrompt();

    return (
        <button
            type="button"
            onClick={async () => {
                onResult(await prompt(options));
            }}
        >
            trigger-prompt
        </button>
    );
}

function AlertHarness({ options, onResult }) {
    const alert = useAlert();

    return (
        <button
            type="button"
            onClick={async () => {
                await alert(options);
                onResult(true);
            }}
        >
            trigger-alert
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

function mountPrompt(options = {}) {
    const results = [];
    render(
        <ConfirmProvider>
            <PromptHarness options={options} onResult={(value) => results.push(value)} />
        </ConfirmProvider>,
    );
    return results;
}

function mountAlert(options = {}) {
    const results = [];
    render(
        <ConfirmProvider>
            <AlertHarness options={options} onResult={(value) => results.push(value)} />
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

    it('supports warning variant and custom cancelLabel', async () => {
        const results = mount({
            title: 'Complete review cycle?',
            message: 'This cannot be undone.',
            variant: 'warning',
            confirmLabel: 'Complete',
            cancelLabel: 'Keep open',
        });

        fireEvent.click(screen.getByText('trigger'));
        await screen.findByRole('dialog');

        expect(screen.getByText('Complete review cycle?')).toBeInTheDocument();
        expect(screen.getByText('This cannot be undone.')).toBeInTheDocument();
        expect(screen.getByText('Keep open')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Keep open'));

        await waitFor(() => expect(results).toEqual([false]));
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

describe('PromptDialog / usePrompt', () => {
    it('opens prompt dialog, accepts typing, and resolves entered text', async () => {
        const results = mountPrompt({
            title: 'Reason for rejection',
            message: 'Please provide details:',
            defaultValue: 'Initial draft',
            confirmLabel: 'Submit Reason',
        });

        fireEvent.click(screen.getByText('trigger-prompt'));

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toBeInTheDocument();
        expect(screen.getByText('Reason for rejection')).toBeInTheDocument();

        const input = screen.getByRole('textbox');
        expect(input).toHaveValue('Initial draft');

        fireEvent.change(input, { target: { value: 'Incomplete document provided' } });
        fireEvent.click(screen.getByText('Submit Reason'));

        await waitFor(() => expect(results).toEqual(['Incomplete document provided']));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('resolves null when user cancels prompt', async () => {
        const results = mountPrompt({ title: 'Enter value' });

        fireEvent.click(screen.getByText('trigger-prompt'));
        await screen.findByRole('dialog');

        fireEvent.click(screen.getByText('Cancel'));

        await waitFor(() => expect(results).toEqual([null]));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('supports multiline textarea in prompt', async () => {
        mountPrompt({
            title: 'Feedback',
            multiline: true,
            placeholder: 'Type feedback…',
        });

        fireEvent.click(screen.getByText('trigger-prompt'));
        await screen.findByRole('dialog');

        const textarea = screen.getByPlaceholderText('Type feedback…');
        expect(textarea.tagName).toBe('TEXTAREA');
    });

    it('throws when usePrompt is used outside a ConfirmProvider', () => {
        function Bare() {
            usePrompt();
            return null;
        }

        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
        expect(() => render(<Bare />)).toThrow(/ConfirmProvider/);
        spy.mockRestore();
    });
});

describe('AlertDialog / useAlert', () => {
    it('shows alert dialog and resolves when OK is clicked', async () => {
        const results = mountAlert({
            title: 'Sync completed',
            message: 'All tasks have synchronized successfully.',
        });

        fireEvent.click(screen.getByText('trigger-alert'));

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toBeInTheDocument();
        expect(screen.getByText('Sync completed')).toBeInTheDocument();
        expect(screen.getByText('All tasks have synchronized successfully.')).toBeInTheDocument();

        fireEvent.click(screen.getByText('OK'));

        await waitFor(() => expect(results).toEqual([true]));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
