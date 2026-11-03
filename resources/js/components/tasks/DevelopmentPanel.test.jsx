import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DevelopmentPanel from './DevelopmentPanel';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('../../services/api', () => ({ default: { get: (...a) => get(...a) } }));

const task = { id: 5, key: 'DEV-5' };
const base = { has_repository: true, can_create_branch: false, repositories: [], pull_requests: [], commits: [], branches: [] };
const show = (dev) => get.mockResolvedValue({ data: { development: { ...base, ...dev } } });

describe('DevelopmentPanel', () => {
    beforeEach(() => get.mockReset());

    it('points a project without a repository at its settings', async () => {
        show({ has_repository: false });
        render(<DevelopmentPanel task={task} projectId={1} />);

        expect(await screen.findByText(/no repository linked/i)).toBeInTheDocument();
    });

    it('tells people how to link work to the task when nothing has happened yet', async () => {
        show({});
        render(<DevelopmentPanel task={task} projectId={1} />);

        expect(await screen.findByText(/include DEV-5 in a branch name/i)).toBeInTheDocument();
    });

    it('shows pull requests with their state and checks, commits and branches', async () => {
        show({
            pull_requests: [{ number: 7, title: 'Fix login', state: 'merged', checks_state: 'failure', repository: 'acme/api', author_login: 'octo', html_url: 'https://github.com/acme/api/pull/7', head_branch: 'fix' }],
            commits: [{ sha: 'abcdef123456', message: 'Do the thing', author_login: 'octo', repository: 'acme/api', html_url: 'https://github.com/acme/api/commit/abc', committed_at: null }],
            branches: [{ name: 'feature/DEV-5', repository: 'acme/api', html_url: 'https://github.com/acme/api/tree/x', deleted: false }, { name: 'old', repository: 'acme/api', html_url: null, deleted: true }],
        });
        render(<DevelopmentPanel task={task} projectId={1} />);

        const link = await screen.findByRole('link', { name: 'Fix login' });
        expect(link).toHaveAttribute('href', 'https://github.com/acme/api/pull/7');
        expect(screen.getByText('Merged')).toBeInTheDocument();
        expect(screen.getByText('Checks failed')).toBeInTheDocument();
        expect(screen.getByText('Do the thing')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'feature/DEV-5' })).toBeInTheDocument();
        expect(screen.getByText('deleted')).toBeInTheDocument();
        expect(get).toHaveBeenCalledWith('/projects/1/tasks/5/development', expect.anything());
    });
});
