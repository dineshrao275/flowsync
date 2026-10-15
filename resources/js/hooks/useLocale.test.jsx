import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import i18n, { DEFAULT_LOCALE } from '../i18n';

const put = vi.fn();
const refresh = vi.fn();

vi.mock('../services/api', () => ({
    default: { put: (...args) => put(...args) },
}));

vi.mock('../context/AuthContext', () => ({
    useAuth: () => ({ user: null, refresh }),
}));

import useLocale from './useLocale';

function Harness() {
    const { locale, setLocale } = useLocale();

    return (
        <div>
            <span data-testid="locale">{locale}</span>
            <button type="button" onClick={() => setLocale('es')}>
                switch
            </button>
        </div>
    );
}

describe('useLocale', () => {
    afterEach(async () => {
        window.localStorage.removeItem('flowsync.locale');
        await i18n.changeLanguage(DEFAULT_LOCALE);
        put.mockClear();
        refresh.mockClear();
    });

    it('switches instantly for guests without calling the API', async () => {
        render(<Harness />);

        await act(async () => {
            screen.getByRole('button', { name: 'switch' }).click();
        });

        expect(screen.getByTestId('locale')).toHaveTextContent('es');
        expect(window.localStorage.getItem('flowsync.locale')).toBe('es');
        expect(put).not.toHaveBeenCalled();
    });
});
