import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PayrollRunDetail from './PayrollRunDetail';

const get = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => true, user: {} }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../components/ui/ConfirmDialog', () => ({ useConfirm: () => vi.fn() }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const payslip = {
    id: 1, employee: { id: 7, name: 'Pat Pay', employee_code: 'E7' }, status: 'draft', gross_pay: '100.00', net_pay: '90.00',
    paid_days: '30', working_days: '30', lop_days: '0', ot_minutes: 0, leave_days: [], earnings: [], deductions: [], statutory: [],
    adjustments: [
        { id: 11, kind: 'earning', label: 'Joining bonus', amount: '50.00', generated: false },
        { id: 12, kind: 'deduction', label: 'Loan instalment 1/3', amount: '10.00', generated: true },
    ],
};

describe('PayrollRunDetail adjustments', () => {
    beforeEach(() => {
        get.mockReset();
        get.mockResolvedValue({ data: { run: { id: 3, status: 'review', period_year: 2026, period_month: 8 }, payslips: [payslip] } });
    });

    it('offers Remove on a manual line but not on a line a loan instalment generated', async () => {
        render(<MemoryRouter initialEntries={['/hrms/payroll/runs/3']}><Routes><Route path="/hrms/payroll/runs/:runId" element={<PayrollRunDetail />} /></Routes></MemoryRouter>);

        fireEvent.click(await screen.findByRole('button', { name: 'Pat Pay' }));
        await screen.findAllByText('Joining bonus');

        // The removal list: rows that pair a label with an optional Remove button.
        const rows = [...document.querySelectorAll('div.py-1.text-sm')];
        const rowFor = (label) => rows.find((row) => row.textContent.startsWith(label));

        expect(rowFor('Joining bonus').querySelector('button')).not.toBeNull();
        expect(rowFor('Loan instalment 1/3').querySelector('button')).toBeNull();
    });
});
