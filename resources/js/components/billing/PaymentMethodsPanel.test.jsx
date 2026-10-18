import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import PaymentMethodsPanel from './PaymentMethodsPanel';
import '../../i18n';

const get = vi.fn();
vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: vi.fn(), delete: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../ui/ConfirmDialog', () => ({ useConfirm: () => async () => true }));

const cards = [
    { id: 'pm_1', brand: 'visa', last4: '4242', exp_month: 12, exp_year: 2031, is_default: true },
    { id: 'pm_2', brand: 'mastercard', last4: '4444', exp_month: 1, exp_year: 2030, is_default: false },
];

describe('PaymentMethodsPanel', () => {
    beforeEach(() => get.mockReset());

    it('lists cards, marks the default and offers management to admins', async () => {
        get.mockResolvedValue({ data: { methods: cards, supported: true } });
        render(<PaymentMethodsPanel canManage />);

        expect(await screen.findByText('•••• 4242')).toBeInTheDocument();
        expect(screen.getByText('Default')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add a card' })).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Remove' })).toHaveLength(2);
        expect(screen.getAllByRole('button', { name: 'Make default' })).toHaveLength(1); // only the non-default card
    });

    it('is read-only without billing rights and absent when the provider cannot manage cards', async () => {
        get.mockResolvedValue({ data: { methods: cards, supported: true } });
        const { unmount } = render(<PaymentMethodsPanel canManage={false} />);
        await screen.findByText('•••• 4242');
        expect(screen.queryByRole('button', { name: 'Add a card' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Remove' })).toBeNull();
        unmount();

        get.mockResolvedValue({ data: { methods: [], supported: false } });
        const { container } = render(<PaymentMethodsPanel canManage />);
        await waitFor(() => expect(container).toBeEmptyDOMElement());
    });
});
