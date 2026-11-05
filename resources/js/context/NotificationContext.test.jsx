import { render, screen, act } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BrowserRouter } from 'react-router-dom';
import { NotificationProvider, useNotifications } from './NotificationContext';

vi.mock('../services/api', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: {} }),
        post: vi.fn().mockResolvedValue({ data: {} }),
    },
}));

let mockUser = null;

vi.mock('./AuthContext', () => ({
    useAuth: () => ({
        user: mockUser,
        check: () => true,
    }),
}));

vi.mock('./ToastContext', () => ({
    useToast: () => ({
        info: vi.fn(),
        success: vi.fn(),
        error: vi.fn(),
    }),
}));

function Consumer() {
    const {
        effectivePlatformCounts,
        effectiveInboxUnread,
        markSectionViewed,
    } = useNotifications();

    return (
        <div>
            <div data-testid="leads-count">{effectivePlatformCounts.leads}</div>
            <div data-testid="support-count">{effectivePlatformCounts.support}</div>
            <div data-testid="queue-count">{effectivePlatformCounts.queue}</div>
            <div data-testid="inbox-count">{effectiveInboxUnread}</div>
            <button
                data-testid="mark-leads-viewed"
                onClick={() => markSectionViewed('leads')}
            >
                View Leads
            </button>
            <button
                data-testid="mark-inbox-viewed"
                onClick={() => markSectionViewed('inbox')}
            >
                View Inbox
            </button>
        </div>
    );
}

function renderWithProviders(user, location = '/admin') {
    mockUser = user;
    window.history.pushState({}, 'Test', location);

    return render(
        <BrowserRouter>
            <NotificationProvider>
                <Consumer />
            </NotificationProvider>
        </BrowserRouter>
    );
}

describe('NotificationContext sidebar badge persistence', () => {
    beforeEach(() => {
        window.localStorage.clear();
        window.sessionStorage.clear();
        vi.clearAllMocks();
    });

    it('does not show badges on login if the user already saw them in localStorage', async () => {
        const user = { id: 1, is_super_admin: true };

        // Pre-populate localStorage as if the user previously saw 5 leads, 3 tickets, 2 failed queue items
        window.localStorage.setItem('flowsync_platform_viewed_sections_1', JSON.stringify({
            leads: true,
            support: true,
            queue: true,
        }));
        window.localStorage.setItem('flowsync_seen_section_counts_1', JSON.stringify({
            leads: 5,
            support: 3,
            queue: 2,
        }));

        renderWithProviders(user, '/admin');

        // Since user already saw them, effective counts should be 0
        expect(screen.getByTestId('leads-count').textContent).toBe('0');
        expect(screen.getByTestId('support-count').textContent).toBe('0');
        expect(screen.getByTestId('queue-count').textContent).toBe('0');
    });

    it('marks a section viewed and persists it to localStorage so it is not shown on subsequent loads', async () => {
        const user = { id: 1, is_super_admin: true };

        renderWithProviders(user, '/admin');

        act(() => {
            screen.getByTestId('mark-leads-viewed').click();
        });

        expect(screen.getByTestId('leads-count').textContent).toBe('0');

        const saved = JSON.parse(window.localStorage.getItem('flowsync_platform_viewed_sections_1') || '{}');
        expect(saved.leads).toBe(true);
    });

    it('tracks effective inbox unread for tenant users and respects seen state across sessions', async () => {
        const user = { id: 2, is_super_admin: false };

        window.localStorage.setItem('flowsync_platform_viewed_sections_2', JSON.stringify({
            inbox: true,
        }));
        window.localStorage.setItem('flowsync_seen_section_counts_2', JSON.stringify({
            inbox: 4,
        }));

        renderWithProviders(user, '/dashboard');

        expect(screen.getByTestId('inbox-count').textContent).toBe('0');
    });

    describe('polling cost', () => {
        const calls = async () => (await import('../services/api')).default.get.mock.calls.map(([path]) => path);

        afterEach(() => {
            vi.useRealTimers();
            Object.defineProperty(document, 'hidden', { configurable: true, value: false });
        });

        it('does not request the HR inbox for a tenant without the inbox module', async () => {
            renderWithProviders({ id: 3, is_super_admin: false, modules: ['time_tracking'], products: { tms: true, hrms: false } }, '/dashboard');
            await act(async () => {});

            const paths = await calls();
            expect(paths).toContain('/notifications/unread');
            expect(paths).not.toContain('/hrms/inbox');
        });

        it('requests the HR inbox when the module is on', async () => {
            renderWithProviders({ id: 4, is_super_admin: false, modules: ['hrms.core', 'hrms.inbox'], products: { hrms: true } }, '/dashboard');
            await act(async () => {});

            expect(await calls()).toContain('/hrms/inbox');
        });

        it('stops polling while the tab is hidden and refreshes once when it returns', async () => {
            vi.useFakeTimers();
            renderWithProviders({ id: 5, is_super_admin: false, modules: [], products: {} }, '/dashboard');
            await act(async () => {});
            const initial = (await calls()).length;

            Object.defineProperty(document, 'hidden', { configurable: true, value: true });
            await act(async () => {
                vi.advanceTimersByTime(180000);
            });
            expect((await calls()).length).toBe(initial);

            Object.defineProperty(document, 'hidden', { configurable: true, value: false });
            await act(async () => {
                document.dispatchEvent(new Event('visibilitychange'));
            });
            expect((await calls()).length).toBeGreaterThan(initial);
        });
    });
});
