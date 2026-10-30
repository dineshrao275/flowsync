import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ComponentsPanel from './ComponentsPanel';

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

const members = [{ id: 7, name: 'Ava Lead', email: 'ava@example.test' }];
const component = {
    id: 3,
    name: 'Backend API',
    description: 'Core API services',
    lead_user_id: 7,
    lead: members[0],
    tasks_count: 2,
};

beforeEach(() => {
    get.mockReset();
    post.mockReset();
    put.mockReset();
    del.mockReset();
    confirm.mockReset();
    confirm.mockResolvedValue(true);
});

describe('ComponentsPanel', () => {
    it('lists components with their lead and task count', async () => {
        get.mockResolvedValue({ data: { components: [component] } });

        render(<ComponentsPanel projectId={1} members={members} canManage />);

        expect(await screen.findByText('Backend API')).toBeInTheDocument();
        expect(screen.getByText('Core API services')).toBeInTheDocument();
        expect(screen.getByText('Ava Lead')).toBeInTheDocument();
        expect(screen.getByText('2 tasks')).toBeInTheDocument();
    });

    it('creates a component and reloads the list', async () => {
        get.mockResolvedValue({ data: { components: [] } });
        post.mockResolvedValue({ data: {} });

        render(<ComponentsPanel projectId={1} members={members} canManage />);
        await screen.findByText('No components yet.');

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Frontend UI' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add component' }));

        await waitFor(() =>
            expect(post).toHaveBeenCalledWith('/projects/1/components', {
                name: 'Frontend UI',
                description: null,
                lead_user_id: null,
            }),
        );
        await waitFor(() => expect(get).toHaveBeenCalledTimes(2));
    });

    it('edits the selected component', async () => {
        get.mockResolvedValue({ data: { components: [component] } });
        put.mockResolvedValue({ data: {} });

        render(<ComponentsPanel projectId={1} members={members} canManage />);
        fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Backend Core' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() =>
            expect(put).toHaveBeenCalledWith(
                '/projects/1/components/3',
                expect.objectContaining({ name: 'Backend Core', lead_user_id: 7 }),
            ),
        );
    });

    it('asks before deleting and then removes the component', async () => {
        get.mockResolvedValue({ data: { components: [component] } });
        del.mockResolvedValue({ data: {} });

        render(<ComponentsPanel projectId={1} members={members} canManage />);
        fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));

        await waitFor(() => expect(confirm).toHaveBeenCalled());
        await waitFor(() => expect(del).toHaveBeenCalledWith('/projects/1/components/3'));
    });

    it('shows a duplicate-name validation error', async () => {
        get.mockResolvedValue({ data: { components: [] } });
        post.mockRejectedValue({
            response: { data: { errors: { name: ['A component with this name already exists in this project.'] } } },
        });

        render(<ComponentsPanel projectId={1} members={members} canManage />);
        await screen.findByText('No components yet.');

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Backend API' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add component' }));

        expect(await screen.findByText('A component with this name already exists in this project.')).toBeInTheDocument();
    });

    it('is read-only when the caller cannot manage the project', async () => {
        get.mockResolvedValue({ data: { components: [component] } });

        render(<ComponentsPanel projectId={1} members={members} canManage={false} />);

        expect(await screen.findByText('Backend API')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
        expect(screen.getByText('Restore the project to change its components.')).toBeInTheDocument();
    });
});
