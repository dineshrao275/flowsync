import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Talent from './Talent';

const get = vi.fn();

vi.mock('../../services/api', () => ({ default: { get: (...a) => get(...a), post: vi.fn() }, fieldErrors: () => ({}) }));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => true }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../components/ui/ConfirmDialog', () => ({ useConfirm: () => vi.fn(), default: () => vi.fn() }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

describe('Talent cycle picker', () => {
    beforeEach(() => {
        get.mockReset();
        get.mockImplementation((url) => {
            if (url === '/hrms/performance/cycles') {
                return Promise.resolve({ data: { cycles: [{ id: 1, name: 'H1 cycle', stage: 'check_in' }, { id: 2, name: 'H2 cycle', stage: 'calibration' }] } });
            }
            if (/talent\/cycles\/\d+\/reviews/.test(url)) {
                return Promise.resolve({ data: { reviews: [{ id: 9, status: 'draft', manager_rating: 3, calibrated_rating: null, employee: { name: 'Ada' } }] } });
            }
            return Promise.resolve({ data: { reviews: [], distribution: { by_rating: {}, total: 0 }, feedback_summary: [] } });
        });
    });

    it('lists every cycle as a choice and selects the one in calibration', async () => {
        render(<MemoryRouter><Talent /></MemoryRouter>);

        const select = await screen.findByLabelText('Review cycle');
        expect(screen.getByRole('option', { name: 'H1 cycle' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'H2 cycle' })).toBeInTheDocument();
        expect(select).toHaveValue('2');
    });

    it('offers the 1-5 ratings in the calibrate dialog', async () => {
        render(<MemoryRouter><Talent /></MemoryRouter>);

        fireEvent.click(await screen.findByRole('button', { name: 'Calibrate' }));
        const dialog = await screen.findByRole('dialog');
        const choices = within(dialog).getAllByRole('option').map((o) => o.value);

        expect(choices).toEqual(['1', '2', '3', '4', '5']);
    });
});
