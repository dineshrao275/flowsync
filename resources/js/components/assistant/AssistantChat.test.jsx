import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import AssistantChat from './AssistantChat';
import ResultCards from './ResultCards';

const base = { messages: [], busy: false, error: null, status: { available: true, tools: ['search_tasks'] }, send: vi.fn(), stop: vi.fn(), dismissError: vi.fn() };

const renderChat = (assistant) => render(<MemoryRouter><AssistantChat assistant={{ ...base, ...assistant }} /></MemoryRouter>);

describe('AssistantChat', () => {
    it('offers only starter prompts whose tool the user has', () => {
        renderChat({});

        expect(screen.getByText('Show me all overdue tasks assigned to me')).toBeInTheDocument();
        expect(screen.queryByText('What is my leave balance?')).not.toBeInTheDocument();
    });

    it('sends on Enter, keeps Shift+Enter for a newline, and ignores an empty draft', () => {
        const send = vi.fn();
        renderChat({ send });
        const input = screen.getByLabelText('Message the assistant');

        fireEvent.keyDown(input, { key: 'Enter' });
        expect(send).not.toHaveBeenCalled();

        fireEvent.change(input, { target: { value: 'hello' } });
        fireEvent.keyDown(input, { key: 'Enter', shiftKey: true });
        expect(send).not.toHaveBeenCalled();

        fireEvent.keyDown(input, { key: 'Enter' });
        expect(send).toHaveBeenCalledWith('hello');
        expect(input).toHaveValue('');
    });

    it('shows a stop button while a reply is in progress', () => {
        const stop = vi.fn();
        renderChat({ busy: true, stop, messages: [{ id: 1, role: 'user', content: 'q' }, { id: 2, role: 'assistant', content: '', pending: true, tools: ['search_tasks'] }] });

        expect(screen.getByText('Searching tasks…')).toBeInTheDocument();
        fireEvent.click(screen.getByLabelText('Stop'));
        expect(stop).toHaveBeenCalled();
    });

    it('explains a failure by its code and offers a retry of the same text', () => {
        const send = vi.fn();
        const dismissError = vi.fn();
        renderChat({ send, dismissError, error: { code: 'ai_limit', message: 'x', retryText: 'again please' } });

        expect(screen.getByRole('alert')).toHaveTextContent('daily AI limit');
        fireEvent.click(screen.getByText('Try again'));
        expect(dismissError).toHaveBeenCalled();
        expect(send).toHaveBeenCalledWith('again please');
    });

    it('renders message text as text, never as HTML', () => {
        renderChat({ messages: [{ id: 1, role: 'assistant', content: '<img src=x onerror=alert(1)> **bold**', cards: [] }] });

        expect(screen.getByText(/<img src=x/)).toBeInTheDocument();
        expect(document.querySelector('img')).toBeNull();
    });
});

describe('ResultCards', () => {
    it('links in-app paths and refuses anything else as a link', () => {
        render(
            <MemoryRouter>
                <ResultCards cards={[
                    { title: 'AI-1 · Fix login', subtitle: 'Web', badges: ['overdue'], link: '/projects/3?tab=tasks&task=AI-1' },
                    { title: 'Evil', link: 'https://evil.example/x' },
                    { title: 'Sneaky', link: '//evil.example/x' },
                ]}
                />
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: /AI-1 · Fix login/ })).toHaveAttribute('href', '/projects/3?tab=tasks&task=AI-1');
        expect(screen.getAllByRole('link')).toHaveLength(1);
        expect(screen.getByText('Evil')).toBeInTheDocument();
    });
});
