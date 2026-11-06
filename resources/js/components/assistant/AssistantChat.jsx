import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowUp, FileArrowUp, Sparkle, Stop } from '@phosphor-icons/react';
import ResultCards from './ResultCards';
import DocumentTasksModal from './DocumentTasksModal';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import { fieldClass } from '../ui/fieldStyles';

// A starter prompt is offered only when the tool it needs is available to this user.
const STARTERS = [
    { key: 'overdue', needs: 'search_tasks' },
    { key: 'dueSoon', needs: 'search_tasks' },
    { key: 'projects', needs: 'list_projects' },
    { key: 'leaveBalance', needs: 'my_leave_balance' },
    { key: 'leaveRequests', needs: 'leave_requests' },
    { key: 'whereIs', needs: 'navigation_help' },
];

function Message({ message, onNavigate }) {
    const { t } = useTranslation();
    const mine = message.role === 'user';

    return (
        <li className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[92%] ${mine ? 'rounded-2xl rounded-br-sm bg-[var(--accent)] px-3.5 py-2 text-[var(--accent-contrast)]' : ''}`}>
                {message.pending && !message.content ? (
                    <p className="flex items-center gap-2 text-sm text-fg-muted" role="status">
                        <Sparkle size={16} className="motion-safe:animate-pulse" aria-hidden="true" />
                        {message.tools?.length ? t(`assistant.tools.${message.tools[message.tools.length - 1]}`, { defaultValue: t('assistant.working') }) : t('assistant.thinking')}
                    </p>
                ) : (
                    <p className={`whitespace-pre-wrap break-words text-sm ${mine ? '' : 'text-fg'}`}>{message.content}</p>
                )}
                {message.stopped ? <p className="mt-1 text-caption text-fg-subtle">{t('assistant.stopped')}</p> : null}
                {!mine ? <ResultCards cards={message.cards} onNavigate={onNavigate} /> : null}
            </div>
        </li>
    );
}

/** `onNavigate` runs when a result link is followed, so the side panel can get out of the way of the page it opens. */
export default function AssistantChat({ assistant, autoFocus = false, onNavigate }) {
    const { t } = useTranslation();
    const { messages, busy, error, status, send, stop, dismissError } = assistant;
    const [draft, setDraft] = useState('');
    const [docsOpen, setDocsOpen] = useState(false);
    const logRef = useRef(null);
    const inputRef = useRef(null);
    const tools = status?.tools ?? [];

    useEffect(() => {
        const log = logRef.current;
        if (log) log.scrollTop = log.scrollHeight;
    }, [messages]);

    useEffect(() => {
        if (autoFocus) inputRef.current?.focus();
    }, [autoFocus]);

    function submit(event) {
        event?.preventDefault();
        if (!draft.trim() || busy) return;
        send(draft);
        setDraft('');
    }

    function onKeyDown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) submit(event);
    }

    const starters = STARTERS.filter((s) => tools.includes(s.needs));
    const errorText = error ? t(`assistant.errors.${error.code}`, { defaultValue: error.message || t('assistant.errors.ai_error') }) : null;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div ref={logRef} className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                {messages.length === 0 ? (
                    <div className="space-y-4">
                        <p className="text-sm text-fg-muted">{t('assistant.intro')}</p>
                        {status?.documents ? (
                            <button type="button" onClick={() => setDocsOpen(true)} className="flex w-full items-start gap-3 rounded-lg border border-dashed border-line px-3 py-3 text-left transition-colors hover:border-[var(--accent)] hover:bg-surface-sunken">
                                <FileArrowUp size={20} className="mt-0.5 shrink-0 text-[var(--accent)]" aria-hidden="true" />
                                <span><span className="block text-sm font-medium text-fg">{t('assistant.documents.entry')}</span><span className="block text-caption text-fg-muted">{t('assistant.documents.entryHint')}</span></span>
                            </button>
                        ) : null}
                        {starters.length > 0 ? (
                            <ul className="space-y-2" aria-label={t('assistant.suggestions')}>
                                {starters.map((s) => (
                                    <li key={s.key}>
                                        <button type="button" disabled={busy} onClick={() => send(t(`assistant.starters.${s.key}`))} className="w-full rounded-lg border border-line px-3 py-2 text-left text-sm text-fg transition-colors hover:border-[var(--accent)] hover:bg-surface-sunken disabled:opacity-60">
                                            {t(`assistant.starters.${s.key}`)}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                ) : (
                    <ul className="space-y-4" role="log" aria-live="polite" aria-label={t('assistant.conversation')}>
                        {messages.map((m) => <Message key={m.id} message={m} onNavigate={onNavigate} />)}
                    </ul>
                )}
                {error ? (
                    <div className="mt-4 space-y-2">
                        <Alert type="error">{errorText}</Alert>
                        <div className="flex gap-2">
                            {error.retryText ? <Button size="sm" variant="secondary" onClick={() => { dismissError(); send(error.retryText); }}>{t('assistant.retry')}</Button> : null}
                            <Button size="sm" variant="ghost" onClick={dismissError}>{t('assistant.dismiss')}</Button>
                        </div>
                    </div>
                ) : null}
            </div>

            {status?.documents ? <DocumentTasksModal open={docsOpen} onClose={() => setDocsOpen(false)} /> : null}

            <form onSubmit={submit} className="border-t border-line p-3">
                <label htmlFor="assistant-input" className="sr-only">{t('assistant.inputLabel')}</label>
                <div className="flex items-end gap-2">
                    <textarea
                        id="assistant-input"
                        ref={inputRef}
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={onKeyDown}
                        rows={2}
                        maxLength={8000}
                        placeholder={t('assistant.placeholder')}
                        className={`${fieldClass} min-h-[2.75rem] resize-none`}
                    />
                    {busy ? (
                        <Button type="button" size="icon" variant="secondary" onClick={stop} aria-label={t('assistant.stop')}><Stop size={18} weight="fill" aria-hidden="true" /></Button>
                    ) : (
                        <Button type="submit" size="icon" disabled={!draft.trim()} aria-label={t('assistant.send')}><ArrowUp size={18} weight="bold" aria-hidden="true" /></Button>
                    )}
                </div>
                <p className="mt-1.5 text-caption text-fg-subtle">{t('assistant.disclaimer')}</p>
            </form>
        </div>
    );
}
