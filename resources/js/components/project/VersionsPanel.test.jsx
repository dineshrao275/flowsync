import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import VersionsPanel from './VersionsPanel';

const { get, post, put, del, confirm } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    del: vi.fn(),
    confirm: vi.fn(),
}));

vi.mock('../../services/api', () => ({
    default: {
        get: (...args) => get(...args),
        post: (...args) => post(...args),
        put: (...args) => put(...args),
        delete: (...args) => del(...args),
    },
    fieldErrors: (error) => {
        const payload = error?.response?.data;
        if (payload?.errors) {
            return Object.fromEntries(
                Object.entries(payload.errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]),
            );
        }
        return { form: payload?.message ?? 'error' };
    },
}));
vi.mock('../../context/ToastContext', () => ({
    useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }),
}));
vi.mock('../ui/ConfirmDialog', () => ({ useConfirm: () => confirm }));

const version = {
    id: 5,
    name: 'v1.0.0',
    description: 'Initial release',
    release_date: '2026-12-31',
    released: true,
    archived: false,
    tasks_count: 4,
};

beforeEach(() => {
    get.mockReset();
    post.mockReset();
    put.mockReset();
    del.mockReset();
    confirm.mockReset();
    confirm.mockResolvedValue(true);
});

describe('VersionsPanel', () => {
    it('lists versions with release state and task count', async () => {
        get.mockResolvedValue({ data: { versions: [version] } });

        render(<VersionsPanel projectId={1} canManage />);

        expect(await screen.findByText('v1.0.0')).toBeInTheDocument();
        expect(screen.getByText('Initial release')).toBeInTheDocument();
        expect(screen.getByText('4 tasks')).toBeInTheDocument();
        expect(screen.getAllByText('Released').length).toBeGreaterThan(0);
    });

    it('creates a version with its release date', async () => {
        get.mockResolvedValue({ data: { versions: [] } });
        post.mockResolvedValue({ data: {} });

        render(<VersionsPanel projectId={1} canManage />);
        await screen.findByText('No versions yet.');

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'v2.0.0' } });
        fireEvent.change(screen.getByLabelText('Release date'), { target: { value: '2027-01-15' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add version' }));

        await waitFor(() =>
            expect(post).toHaveBeenCalledWith('/projects/1/versions', {
                name: 'v2.0.0',
                description: null,
                release_date: '2027-01-15',
                released: false,
                archived: false,
            }),
        );
    });

    it('edits the selected version and can mark it released', async () => {
        get.mockResolvedValue({ data: { versions: [version] } });
        put.mockResolvedValue({ data: {} });

        render(<VersionsPanel projectId={1} canManage />);
        fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'v1.0.1' } });
        fireEvent.click(screen.getByLabelText('Released'));
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() =>
            expect(put).toHaveBeenCalledWith(
                '/projects/1/versions/5',
                expect.objectContaining({ name: 'v1.0.1', released: false, archived: false }),
            ),
        );
    });

    it('asks before deleting', async () => {
        get.mockResolvedValue({ data: { versions: [version] } });
        del.mockResolvedValue({ data: {} });

        render(<VersionsPanel projectId={1} canManage />);
        fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));

        await waitFor(() => expect(confirm).toHaveBeenCalled());
        await waitFor(() => expect(del).toHaveBeenCalledWith('/projects/1/versions/5'));
    });

    it('is read-only when the caller cannot manage the project', async () => {
        get.mockResolvedValue({ data: { versions: [version] } });

        render(<VersionsPanel projectId={1} canManage={false} />);

        expect(await screen.findByText('v1.0.0')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
        expect(screen.getByText('Restore the project to change its versions.')).toBeInTheDocument();
    });
});
