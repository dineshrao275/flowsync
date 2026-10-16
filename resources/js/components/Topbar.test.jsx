import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from '../context/AuthContext';
import { ToastProvider } from '../context/ToastContext';
import { PageTitleProvider } from '../context/PageTitleContext';
import i18n, { DEFAULT_LOCALE } from '../i18n';
import Topbar from './Topbar';

vi.mock('../services/api', () => ({
    default: {
        get: (url) => {
            if (url === '/locales') {
                return Promise.resolve({
                    data: {
                        default: 'en',
                        locales: {
                            en: { name: 'English', native: 'English' },
                            es: { name: 'Spanish', native: 'Español' },
                        },
                    },
                });
            }

            return Promise.reject(new Error('signed out'));
        },
        put: vi.fn(() => Promise.resolve({ data: {} })),
    },
}));

vi.mock('./NotificationBell', () => ({ default: () => null }));

function Shell() {
    return (
        <MemoryRouter>
            <AuthProvider>
                <ToastProvider>
                    <PageTitleProvider>
                        <Topbar showSearch={false} showTheme={false} />
                    </PageTitleProvider>
                </ToastProvider>
            </AuthProvider>
        </MemoryRouter>
    );
}

describe('Topbar language switcher', () => {
    afterEach(async () => {
        window.localStorage.removeItem('flowsync.locale');
        await i18n.changeLanguage(DEFAULT_LOCALE);
    });

    it('offers completed locales and switches without a reload', async () => {
        render(<Shell />);

        fireEvent.click(await screen.findByRole('button', { name: 'Language' }));

        const spanish = await screen.findByRole('menuitemradio', { name: 'Español' });
        expect(screen.getByRole('menuitemradio', { name: 'English' })).toHaveAttribute('aria-checked', 'true');

        fireEvent.click(spanish);

        await waitFor(() => expect(document.documentElement.lang).toBe('es'));
        expect(window.localStorage.getItem('flowsync.locale')).toBe('es');
    });
});
