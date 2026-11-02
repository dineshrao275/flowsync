import { render, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Statutory from './Statutory';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a), put: vi.fn(), delete: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => ({ can: () => false, user: {} }) }));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }));
vi.mock('../../context/BreadcrumbContext', () => ({ useSetCrumbs: () => () => {} }));
vi.mock('../../components/ui/ConfirmDialog', () => ({ useConfirm: () => vi.fn() }));
vi.mock('../../hooks/usePageTitle', () => ({ default: () => {} }));

const calls = () => get.mock.calls.map(([url]) => url);

describe('Statutory page for an Employee (no manage permission)', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockImplementation((url) => {
            if (url === '/my/hr') return Promise.resolve({ data: { profile: { id: 7, name: 'Pat Self' } } });
            return Promise.resolve({ data: {} });
        });
    });

    it('never asks for the employee directory or the managers’ queues, and reads its own profile instead', async () => {
        render(<MemoryRouter><Statutory /></MemoryRouter>);

        await waitFor(() => expect(calls()).toContain('/my/hr'));
        expect(calls()).not.toContain('/hrms/employees');
        expect(calls()).not.toContain('/hrms/payroll/statutory/declarations');
        expect(calls()).not.toContain('/hrms/payroll/statutory/configurations');
    });
});
