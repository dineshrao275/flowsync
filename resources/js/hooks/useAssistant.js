import { useCallback, useEffect, useRef, useState } from 'react';
import api from '../services/api';
import { AssistantError, streamChat } from '../services/assistantStream';

let nextLocalId = 0;
const localId = () => `local-${(nextLocalId += 1)}`;

/**
 * State machine for one assistant conversation. Messages live here; the server owns the history
 * (reopened by id), and this hook only ever sends the new text plus the conversation id.
 */
export default function useAssistant({ context } = {}) {
    const [messages, setMessages] = useState([]);
    const [conversationId, setConversationId] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [status, setStatus] = useState(null);
    const abortRef = useRef(null);
    const contextRef = useRef(context);
    contextRef.current = context;

    useEffect(() => {
        let cancelled = false;
        api.get('/ai/status').then((response) => !cancelled && setStatus(response.data)).catch(() => !cancelled && setStatus({ available: false }));

        return () => { cancelled = true; };
    }, []);

    useEffect(() => () => abortRef.current?.abort(), []);

    const patchLast = useCallback((patch) => {
        setMessages((current) => current.map((m, i) => (i === current.length - 1 ? { ...m, ...(typeof patch === 'function' ? patch(m) : patch) } : m)));
    }, []);

    const send = useCallback(async (text) => {
        const message = text.trim();
        if (!message || busy) return;

        setError(null);
        setBusy(true);
        setMessages((current) => [...current, { id: localId(), role: 'user', content: message }, { id: localId(), role: 'assistant', content: '', cards: [], pending: true, tools: [] }]);

        const controller = new AbortController();
        abortRef.current = controller;

        try {
            await streamChat({
                message,
                conversationId,
                context: contextRef.current,
                signal: controller.signal,
                onEvent: (event, data) => {
                    if (event === 'start') setConversationId(data.conversation_id);
                    else if (event === 'token') patchLast((m) => ({ content: m.content + data.text }));
                    else if (event === 'reset') patchLast({ content: '' });
                    else if (event === 'tool') patchLast((m) => ({ tools: [...m.tools, data.name] }));
                    else if (event === 'cards') patchLast({ cards: data.cards });
                    else if (event === 'done') {
                        setConversationId(data.conversation_id);
                        patchLast({ content: data.answer, cards: data.cards || [], pending: false });
                    }
                },
            });
            patchLast({ pending: false });
        } catch (caught) {
            if (caught?.name === 'AbortError') {
                patchLast({ pending: false, stopped: true });
            } else {
                const failure = caught instanceof AssistantError ? caught : new AssistantError('error');
                setError({ code: failure.code, message: failure.message, retryText: message });
                setMessages((current) => current.filter((m, i) => !(i === current.length - 1 && m.pending && !m.content)));
            }
        } finally {
            setBusy(false);
            abortRef.current = null;
        }
    }, [busy, conversationId, patchLast]);

    const stop = useCallback(() => abortRef.current?.abort(), []);

    const reset = useCallback(() => {
        abortRef.current?.abort();
        setMessages([]);
        setConversationId(null);
        setError(null);
        setBusy(false);
    }, []);

    const open = useCallback(async (id) => {
        abortRef.current?.abort();
        setError(null);
        try {
            const { data } = await api.get(`/ai/conversations/${id}`);
            setConversationId(data.conversation.id);
            setMessages(data.messages.map((m) => ({ id: `srv-${m.id}`, role: m.role, content: m.content, cards: m.cards || [] })));
        } catch {
            setError({ code: 'load', message: 'load' });
        }
    }, []);

    return { messages, conversationId, busy, error, status, send, stop, reset, open, dismissError: () => setError(null) };
}
