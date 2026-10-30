import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CmsPages from './CmsPages';
import { BreadcrumbProvider } from '../context/BreadcrumbContext';

const get = vi.fn();
const post = vi.fn();
const toast = { success: vi.fn(), error: vi.fn() };

vi.mock('../services/api', () => ({
    default: { get: (...a) => get(...a), post: (...a) => post(...a), put: vi.fn(), delete: vi.fn() },
    fieldErrors: () => ({}),
}));
vi.mock('../context/ToastContext', () => ({ useToast: () => toast }));
vi.mock('../components/ui/ConfirmDialog', () => ({ useConfirm: () => vi.fn() }));
// The block editor mounts rich-text and media pickers that need a browser; they are not under test here.
vi.mock('../components/ui/RichTextEditor', () => ({ default: () => null }));
vi.mock('../components/cms/MediaPicker', () => ({ default: () => null }));

const pages = [{ id: 1, slug: 'home', title: 'Home', status: 'published', sitemap_include: true, sort_order: 0 }];

const renderPage = () => render(<MemoryRouter><BreadcrumbProvider><CmsPages /></BreadcrumbProvider></MemoryRouter>);

describe('CmsPages page', () => {
    beforeEach(() => {
        get.mockReset(); post.mockReset(); toast.success.mockReset();
        get.mockImplementation((url) => Promise.resolve({ data: url === '/system/pages' ? { pages } : { page: { ...pages[0], content: [{ type: 'text', body: 'Hello' }] } } }));
    });

    it('lists the pages and opens the editor with the full page content, blocks included', async () => {
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: /edit/i }));

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByDisplayValue('Hello')).toBeInTheDocument();
        expect(get).toHaveBeenCalledWith('/system/pages/1');
    });

    it('creates a page with blocks added from the block editor', async () => {
        post.mockResolvedValue({ data: { page: { title: 'About' } } });
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'New page' }));

        const dialog = await screen.findByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText(/^Title/), { target: { value: 'About' } });
        fireEvent.change(within(dialog).getByLabelText(/^Slug/), { target: { value: 'about' } });
        fireEvent.click(within(dialog).getByRole('button', { name: '+ text' }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save page' }));

        await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(1));
        const [url, payload] = post.mock.calls[0];
        expect(url).toBe('/system/pages');
        expect(payload).toMatchObject({ title: 'About', slug: 'about', status: 'draft' });
        expect(payload.content).toEqual([expect.objectContaining({ type: 'text' })]);
    });
});
