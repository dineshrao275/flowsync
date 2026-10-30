import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import HrmsOverview from './HrmsOverview';

const modules = ['sprints', 'webhooks', 'jql', 'hrms.core', 'hrms.leave', 'hrms.payroll'];

vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ user: { modules } }) }));
vi.mock('../../components/hrms/SetupChecklist', () => ({ default: () => null }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => {} }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

describe('HrmsOverview', () => {
    it('counts only the HR modules it shows, not every module on the plan', () => {
        render(<MemoryRouter><HrmsOverview /></MemoryRouter>);

        const stated = Number(screen.getByText(/modules? enabled on your plan/i).textContent.match(/\d+/)?.[0] ?? 1);

        expect(stated).toBe(3);
    });
});
