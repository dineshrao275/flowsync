import { afterEach, describe, expect, it, vi } from 'vitest';
import { AssistantError, parseFrames, streamChat } from './assistantStream';

function sseResponse(text, headers = { 'Content-Type': 'text/event-stream' }) {
    const encoder = new TextEncoder();
    // Split mid-frame on purpose: a chunk boundary must never break a frame.
    const mid = Math.floor(text.length / 2);
    const stream = new ReadableStream({
        start(controller) {
            controller.enqueue(encoder.encode(text.slice(0, mid)));
            controller.enqueue(encoder.encode(text.slice(mid)));
            controller.close();
        },
    });

    return new Response(stream, { status: 200, headers });
}

afterEach(() => vi.restoreAllMocks());

describe('parseFrames', () => {
    it('returns complete frames and keeps the unfinished rest', () => {
        const { frames, rest } = parseFrames('event: token\ndata: {"text":"Hi"}\n\nevent: done\ndata: {"a"');

        expect(frames).toEqual([{ event: 'token', data: { text: 'Hi' } }]);
        expect(rest).toBe('event: done\ndata: {"a"');
    });

    it('skips a malformed frame instead of failing the stream', () => {
        const { frames } = parseFrames('event: token\ndata: {nope\n\nevent: token\ndata: {"text":"ok"}\n\n');

        expect(frames).toEqual([{ event: 'token', data: { text: 'ok' } }]);
    });
});

describe('streamChat', () => {
    it('delivers events in order even when a frame is split across chunks', async () => {
        const body = 'event: start\ndata: {"conversation_id":7}\n\nevent: token\ndata: {"text":"Hello"}\n\nevent: done\ndata: {"conversation_id":7,"answer":"Hello","cards":[]}\n\n';
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(sseResponse(body)));
        const seen = [];

        await streamChat({ message: 'hi', onEvent: (event, data) => seen.push([event, data]) });

        expect(seen.map(([event]) => event)).toEqual(['start', 'token', 'done']);
        expect(seen[1][1].text).toBe('Hello');
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/api/ai/chat');
        expect(init.credentials).toBe('include');
        expect(JSON.parse(init.body)).toMatchObject({ message: 'hi', stream: true });
    });

    it('throws an AssistantError carrying the server code, without redirecting', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Limit reached', code: 'ai_limit' }), { status: 429, headers: { 'Content-Type': 'application/json' } })));

        await expect(streamChat({ message: 'hi', onEvent: () => {} })).rejects.toMatchObject({ name: 'AssistantError', code: 'ai_limit', status: 429 });
    });

    it('surfaces an error event sent mid-stream', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(sseResponse('event: error\ndata: {"message":"boom","code":"ai_error"}\n\n')));

        await expect(streamChat({ message: 'hi', onEvent: () => {} })).rejects.toBeInstanceOf(AssistantError);
    });

    it('accepts the plain JSON fallback when the provider cannot stream', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ conversation_id: 3, answer: 'Plain', cards: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } })));
        const seen = [];

        await streamChat({ message: 'hi', onEvent: (event, data) => seen.push([event, data]) });

        expect(seen.map(([event]) => event)).toEqual(['token', 'cards', 'done']);
    });

    it('reports a network failure with its own code', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        await expect(streamChat({ message: 'hi', onEvent: () => {} })).rejects.toMatchObject({ code: 'network' });
    });
});
