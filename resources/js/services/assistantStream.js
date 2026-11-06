/**
 * Streams one assistant turn over POST + server-sent events.
 *
 * axios cannot stream a response body, so this uses fetch with the same cookie session and CSRF header
 * the axios instance sends. It deliberately does NOT use the axios interceptors: their 401/402/403
 * handling hard-redirects the page, which would throw away a half-read answer. Failures are thrown as
 * AssistantError and the caller decides what to show.
 */
export class AssistantError extends Error {
    constructor(message, { code = 'ai_error', status = 0 } = {}) {
        super(message);
        this.name = 'AssistantError';
        this.code = code;
        this.status = status;
    }
}

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

/** Splits a growing SSE buffer into complete `{event, data}` frames and returns the unread rest. */
export function parseFrames(buffer) {
    const frames = [];
    let rest = buffer.replace(/\r\n/g, '\n');
    let end;

    while ((end = rest.indexOf('\n\n')) !== -1) {
        const raw = rest.slice(0, end);
        rest = rest.slice(end + 2);
        let event = 'message';
        const data = [];
        raw.split('\n').forEach((line) => {
            if (line.startsWith('event:')) event = line.slice(6).trim();
            else if (line.startsWith('data:')) data.push(line.slice(5).trim());
        });
        if (data.length) {
            try {
                frames.push({ event, data: JSON.parse(data.join('\n')) });
            } catch {
                /* a malformed frame is skipped, never fatal */
            }
        }
    }

    return { frames, rest };
}

export async function streamChat({ message, conversationId, context, signal, onEvent, baseUrl = '/api' }) {
    const headers = { 'Content-Type': 'application/json', Accept: 'text/event-stream', 'X-Requested-With': 'XMLHttpRequest' };
    const token = xsrfToken();
    if (token) headers['X-XSRF-TOKEN'] = token;

    let response;
    try {
        response = await fetch(`${baseUrl}/ai/chat`, {
            method: 'POST',
            credentials: 'include',
            headers,
            signal,
            body: JSON.stringify({ message, conversation_id: conversationId || undefined, context, stream: true }),
        });
    } catch (error) {
        if (error?.name === 'AbortError') throw error;
        throw new AssistantError('network', { code: 'network' });
    }

    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new AssistantError(body.message || 'The assistant is unavailable.', { code: body.code || `http_${response.status}`, status: response.status });
    }

    // The server falls back to a plain JSON answer when the provider cannot stream.
    if ((response.headers.get('Content-Type') || '').includes('application/json')) {
        const body = await response.json();
        onEvent('token', { text: body.answer });
        onEvent('cards', { cards: body.cards || [] });
        onEvent('done', body);

        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const parsed = parseFrames(buffer);
        buffer = parsed.rest;
        parsed.frames.forEach(({ event, data }) => {
            if (event === 'error') throw new AssistantError(data.message, { code: data.code });
            onEvent(event, data);
        });
    }
}
