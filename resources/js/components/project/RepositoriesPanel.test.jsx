import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RepositoriesPanel from './RepositoriesPanel';

const { get, post, put, del, confirm, perms } = vi.hoisted(() => ({
    get: vi.fn(), post: vi.fn(), put: vi.fn(), del: vi.fn(), confirm: vi.fn(), perms: { module: true, view: true, manage: true },
}));

vi.mock('../../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a), put: (...a) => put(...a), delete: (...a) => del(...a) },
    fieldErrors: (err) => err?.fields ?? {},
}));
vi.mock('../../context/AuthContext', () => ({
    useAuth: () => ({ hasModule: () => perms.module, can: (p) => (p === 'git.view' ? perms.view : perms.manage) }),
}));
vi.mock('../../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../ui/ConfirmDialog', () => ({ useConfirm: () => confirm }));

const linked = { id: 5, full_name: 'acme-org/api', html_url: 'https://github.com/acme-org/api', sync_enabled: true, smart_commits_enabled: false };

describe('RepositoriesPanel', () => {
    beforeEach(() => {
        [get, post, put, del, confirm].forEach((m) => m.mockReset());
        Object.assign(perms, { module: true, view: true, manage: true });
        get.mockImplementation((url) => Promise.resolve({
            data: url.startsWith('/integrations') ? { repositories: [{ uuid: 'r-1', full_name: 'acme-org/web', private: true }, { uuid: 'r-2', full_name: 'acme-org/api', private: false }] } : { repositories: [linked] },
        }));
    });

    it('renders nothing without the git module or permission', () => {
        perms.module = false;
        const { container } = render(<RepositoriesPanel projectId={1} canManage />);

        expect(container).toBeEmptyDOMElement();
        expect(get).not.toHaveBeenCalled();
    });

    it('lists linked repositories, with write controls only for managers', async () => {
        const { rerender } = render(<RepositoriesPanel projectId={1} canManage />);
        expect(await screen.findByText('acme-org/api')).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: /smart commits/i })).toBeInTheDocument();

        perms.manage = false;
        rerender(<RepositoriesPanel projectId={1} canManage={false} />);
        await waitFor(() => expect(screen.queryByRole('button', { name: /^unlink$/i })).not.toBeInTheDocument());
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    });

    it('links a picked repository and blocks one that is already linked', async () => {
        post.mockResolvedValue({ data: {} });
        render(<RepositoriesPanel projectId={1} canManage />);
        await screen.findByText('acme-org/api');

        fireEvent.click(screen.getByRole('button', { name: /link repository/i }));
        expect(await screen.findByText('acme-org/web')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^linked$/i })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: /^link$/i }));
        await waitFor(() => expect(post).toHaveBeenCalledWith('/projects/1/repositories', { repository_uuid: 'r-1' }));
    });

    it('saves a switch immediately and unlinks only after confirmation, asking about linked data separately', async () => {
        put.mockResolvedValue({ data: {} });
        del.mockResolvedValue({ data: {} });
        confirm.mockResolvedValueOnce(true).mockResolvedValueOnce(false);
        render(<RepositoriesPanel projectId={1} canManage />);

        fireEvent.click(await screen.findByRole('switch', { name: /smart commits/i }));
        await waitFor(() => expect(put).toHaveBeenCalledWith('/projects/1/repositories/5', { smart_commits_enabled: true }));

        fireEvent.click(screen.getByRole('button', { name: /^unlink$/i }));
        await waitFor(() => expect(del).toHaveBeenCalledWith('/projects/1/repositories/5', { data: { remove_linked_data: false } }));
    });
});
