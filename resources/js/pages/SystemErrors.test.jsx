import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SystemErrors from './SystemErrors';

const { get, put, post, can } = vi.hoisted(() => ({
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    can: vi.fn(),
}));

vi.mock('../services/api', () => ({
    default: {
        get: (...args) => get(...args),
        put: (...args) => put(...args),
        post: (...args) => post(...args),
    },
}));

vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ can }) }));
vi.mock('../context/ToastContext', () => ({
    useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }),
}));
vi.mock('../context/BreadcrumbContext', () => ({ useSetCrumbs: () => {} }));
vi.mock('../hooks/usePageTitle', () => ({ default: () => {} }));

const summary = {
    total: 3,
    open: 1,
    new_24h: 2,
    statuses: { open: 1, investigating: 0, resolved: 2, ignored: 0 },
    severities: { warning: 0, error: 2, critical: 1, alert: 0, emergency: 0 },
    sources: { backend: 2, queue: 1, console: 0, frontend: 0 },
    modules: [{ module: 'tms', count: 2 }],
    top_recurring: [],
};

const row = {
    id: 9,
    fingerprint: 'abc123',
    exception_class: 'RuntimeException',
    message: 'Boom message',
    severity: 'critical',
    status: 'open',
    module: 'tms',
    source: 'queue',
    environment: 'production',
    tenant_id: 4,
    tenant: 'Acme',
    occurrences: 7,
    last_seen_at: '2026-11-01T10:00:00Z',
    first_seen_at: '2026-10-30T10:00:00Z',
    resolved_at: null,
    recurred: true,
};

const detail = {
    ...row,
    request_id: 'req-123',
    path: 'api/tasks',
    method: 'POST',
    stack_trace: 'Stack trace evidence',
    context: { job: 'App\\Jobs\\Sync' },
    occurrences_preview: [{ id: 1, occurred_at: '2026-11-01T10:00:00Z', path: 'api/tasks', method: 'POST' }],
    notes: [{ id: 2, author_name: 'Ops', body: 'Looking into it', created_at: '2026-11-01T11:00:00Z' }],
    history: [],
};

function mockApi(detailStatus = 'open') {
    get.mockImplementation((url) => {
        if (url === '/system/errors/summary') return Promise.resolve({ data: summary });
        if (url === `/system/errors/${row.id}`) {
            return Promise.resolve({ data: { error: { ...detail, status: detailStatus } } });
        }
        return Promise.resolve({ data: { items: [row], pagination: { current_page: 1, last_page: 1, total: 1 } } });
    });
}

beforeEach(() => {
    get.mockReset();
    put.mockReset();
    post.mockReset();
    can.mockReset();
    can.mockReturnValue(true);
});

describe('SystemErrors page', () => {
    it('shows the fleet summary and one row per error, marking recurrences', async () => {
        mockApi();
        render(<SystemErrors />);

        expect(await screen.findByText('Boom message')).toBeInTheDocument();
        expect(screen.getAllByText('Queue').length).toBeGreaterThan(1);
        expect(screen.getByText('Recurred')).toBeInTheDocument();
        expect(screen.getByText('7')).toBeInTheDocument();
        expect(get).toHaveBeenCalledWith('/system/errors', expect.objectContaining({
            params: expect.objectContaining({ status: 'open' }),
        }));
    });

    it('opens the detail drawer and updates the status with a note', async () => {
        mockApi('resolved');
        put.mockResolvedValue({ data: { message: 'Error status updated.', error: row } });
        render(<SystemErrors />);

        fireEvent.click(await screen.findByText('Boom message'));

        expect(await screen.findByText('Stack trace evidence')).toBeInTheDocument();
        expect(screen.getByText('Looking into it')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'resolved' } });
        fireEvent.change(screen.getByPlaceholderText('What did you find?'), { target: { value: 'Patched' } });
        fireEvent.click(screen.getByRole('button', { name: 'Update status' }));

        await waitFor(() => expect(put).toHaveBeenCalledWith(`/system/errors/${row.id}`, {
            status: 'resolved',
            note: 'Patched',
        }));
    });

    it('selects rows and applies a bulk status update', async () => {
        mockApi();
        post.mockResolvedValue({ data: { message: 'Errors updated.', updated: 1 } });
        render(<SystemErrors />);

        await screen.findByText('Boom message');
        fireEvent.click(screen.getByLabelText('Select this error'));

        expect(await screen.findByText('1 selected')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Mark selected as'), { target: { value: 'ignored' } });
        fireEvent.click(screen.getByRole('button', { name: 'Update selected' }));

        await waitFor(() => expect(post).toHaveBeenCalledWith('/system/errors/bulk', {
            ids: [row.id],
            status: 'ignored',
        }));
    });

    it('hides triage controls from read-only personas', async () => {
        can.mockImplementation((permission) => permission !== 'errors.manage');
        mockApi();
        render(<SystemErrors />);

        fireEvent.click(await screen.findByText('Boom message'));

        expect(await screen.findByText('Stack trace evidence')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Update status' })).not.toBeInTheDocument();
    });
});
