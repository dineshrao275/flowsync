import { act, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import Resource from '../components/ui/Resource';
import { ConfirmProvider } from '../components/ui/ConfirmDialog';
import { optimistic } from '../utils/optimistic';
import useUnsavedChanges from './useUnsavedChanges';
import useUrlState from './useUrlState';

function UrlHarness() {
    const [url, update, reset] = useUrlState({ q: '', page: '1' });
    const location = useLocation();

    return (
        <div>
            <output data-testid="state">{JSON.stringify(url)}</output>
            <output data-testid="search">{location.search}</output>
            <button onClick={() => update({ page: '3' })}>page3</button>
            <button onClick={() => update({ q: 'bug' })}>filter</button>
            <button onClick={reset}>reset</button>
        </div>
    );
}

describe('useUrlState', () => {
    it('reads defaults, keeps defaults out of the URL and resets page on filter change', () => {
        render(<MemoryRouter initialEntries={['/?page=2']}><UrlHarness /></MemoryRouter>);

        expect(JSON.parse(screen.getByTestId('state').textContent)).toEqual({ q: '', page: '2' });

        fireEvent.click(screen.getByText('page3'));
        expect(screen.getByTestId('search').textContent).toBe('?page=3');

        fireEvent.click(screen.getByText('filter'));
        expect(screen.getByTestId('search').textContent).toBe('?q=bug');

        fireEvent.click(screen.getByText('reset'));
        expect(screen.getByTestId('search').textContent).toBe('');
    });
});

describe('optimistic', () => {
    function setup(initial) {
        let state = initial;
        const setState = (next) => { state = typeof next === 'function' ? next(state) : next; };

        return { setState, get: () => state };
    }

    it('applies immediately and keeps the change on success', async () => {
        const store = setup({ n: 1 });
        await optimistic(store.setState, (s) => ({ n: s.n + 1 }), () => Promise.resolve('ok'));

        expect(store.get()).toEqual({ n: 2 });
    });

    it('rolls back and re-throws on failure', async () => {
        const store = setup({ n: 1 });
        const request = vi.fn().mockRejectedValue(new Error('nope'));

        await expect(optimistic(store.setState, (s) => ({ n: s.n + 1 }), request)).rejects.toThrow('nope');
        expect(store.get()).toEqual({ n: 1 });
    });

    it('does not clobber a newer change when rolling back', async () => {
        const store = setup({ n: 1 });
        const request = () => {
            store.setState({ n: 99 }); // something else wrote while the request was in flight
            return Promise.reject(new Error('late'));
        };

        await expect(optimistic(store.setState, (s) => ({ n: s.n + 1 }), request)).rejects.toThrow('late');
        expect(store.get()).toEqual({ n: 99 });
    });
});

describe('useUnsavedChanges', () => {
    const wrapper = ({ children }) => <ConfirmProvider>{children}</ConfirmProvider>;

    it('resolves true straight away when clean and registers no beforeunload', async () => {
        const add = vi.spyOn(window, 'addEventListener');
        const { result } = renderHook(() => useUnsavedChanges(false), { wrapper });

        await expect(result.current()).resolves.toBe(true);
        expect(add.mock.calls.some(([type]) => type === 'beforeunload')).toBe(false);
        add.mockRestore();
    });

    it('asks before discarding when dirty and blocks unload', async () => {
        const { result } = renderHook(() => useUnsavedChanges(true), { wrapper });

        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);

        let answer;
        act(() => { answer = result.current(); });
        fireEvent.click(await screen.findByRole('button', { name: 'Keep editing' }));
        await expect(answer).resolves.toBe(false);
    });
});

describe('Resource', () => {
    it('shows the skeleton on first load, the error with retry, then content', () => {
        const reload = vi.fn();
        const { rerender } = render(<Resource data={null} loading skeleton={<p>loading…</p>}>{() => null}</Resource>);
        expect(screen.getByText('loading…')).toBeInTheDocument();

        rerender(<Resource data={null} loading={false} error={new Error('x')} reload={reload}>{() => null}</Resource>);
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(reload).toHaveBeenCalled();

        rerender(<Resource data={{ n: 5 }} loading>{(d) => <p>value {d.n}</p>}</Resource>);
        expect(screen.getByText('value 5')).toBeInTheDocument();
    });
});

void waitFor;
