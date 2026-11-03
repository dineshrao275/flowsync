import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Integrations from './Integrations';

const { get, del, confirm, can } = vi.hoisted(() => ({ get: vi.fn(), del: vi.fn(), confirm: vi.fn(), can: vi.fn() }));

vi.mock('../services/api', () => ({
    default: { get: (...a) => get(...a), delete: (...a) => del(...a) },
    fieldErrors: () => ({}),
}));
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ can: (p) => can(p) }) }));
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }));
vi.mock('../context/BreadcrumbContext', () => ({ useSetCrumbs: () => vi.fn() }));
vi.mock('../components/ui/ConfirmDialog', () => ({ useConfirm: () => confirm }));

const connection = {
    uuid: 'u-1', provider: 'github', account_login: 'acme-org', account_type: 'Organization', status: 'active',
    repositories_count: 3, connected_by_name: 'Ada', last_synced_at: null,
};

function renderPage(url = '/integrations') {
    return render(<MemoryRouter initialEntries={[url]}><Integrations /></MemoryRouter>);
}

describe('Integrations page', () => {
    beforeEach(() => {
        get.mockReset(); del.mockReset(); confirm.mockReset(); can.mockReset();
        can.mockReturnValue(true);
    });

    it('explains that the feature is off instead of offering a dead button', async () => {
        get.mockResolvedValue({ data: { enabled: false, connections: [] } });
        renderPage();

        expect(await screen.findByText(/not switched on for this server/i)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /connect github/i })).not.toBeInTheDocument();
    });

    it('offers to connect when nothing is connected', async () => {
        get.mockResolvedValue({ data: { enabled: true, connections: [] } });
        renderPage();

        expect(await screen.findByText(/no github account is connected/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /connect github/i })).toBeInTheDocument();
    });

    it('lists a connection with its repository count and hides write actions from a reader', async () => {
        can.mockImplementation((p) => p !== 'git.manage');
        get.mockResolvedValue({ data: { enabled: true, connections: [connection] } });
        renderPage();

        expect(await screen.findByText('acme-org')).toBeInTheDocument();
        expect(screen.getByText('3 repositories')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /disconnect/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /connect github|add another/i })).not.toBeInTheDocument();
    });

    it('disconnects only after the admin confirms', async () => {
        get.mockResolvedValue({ data: { enabled: true, connections: [connection] } });
        del.mockResolvedValue({ data: {} });
        confirm.mockResolvedValueOnce(false).mockResolvedValueOnce(true);
        renderPage();

        const button = await screen.findByRole('button', { name: /^disconnect$/i });
        fireEvent.click(button);
        expect(del).not.toHaveBeenCalled();

        fireEvent.click(button);
        await waitFor(() => expect(del).toHaveBeenCalledWith('/integrations/git/connections/u-1'));
    });

    it('shows the result GitHub redirected back with', async () => {
        get.mockResolvedValue({ data: { enabled: true, connections: [] } });
        renderPage('/integrations?git_error=This+installation+is+taken');

        expect(await screen.findByText('This installation is taken')).toBeInTheDocument();
    });
});
