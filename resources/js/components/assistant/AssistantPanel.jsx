import { useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { ArrowsOutSimple, ClockCounterClockwise, NotePencil, Sparkle, X } from '@phosphor-icons/react';
import AssistantChat from './AssistantChat';
import ConversationList from './ConversationList';
import useAssistant from '../../hooks/useAssistant';
import { useActiveProduct } from '../../context/ProductContext';

/**
 * Right-hand assistant panel. Mounted once in AdminLayout (outside the animated page wrapper, like the
 * theme drawer, so `fixed` is relative to the viewport) and kept mounted while closed so a conversation
 * survives closing the panel. It does not trap the page: you can keep working beside it.
 */
export default function AssistantPanel({ open, onClose }) {
    const { t } = useTranslation();
    const location = useLocation();
    const navigate = useNavigate();
    const { activeProduct } = useActiveProduct();
    const [showHistory, setShowHistory] = useState(false);
    const [refreshKey, setRefreshKey] = useState(0);
    const panelRef = useRef(null);
    const assistant = useAssistant({ context: { route: location.pathname, product: activeProduct } });

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (event) => event.key === 'Escape' && onClose();
        const node = panelRef.current;
        node?.addEventListener('keydown', onKey);

        return () => node?.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    // A finished turn may have created or renamed a conversation.
    useEffect(() => {
        if (!assistant.busy) setRefreshKey((k) => k + 1);
    }, [assistant.busy]);

    function expand() {
        onClose();
        navigate(assistant.conversationId ? `/assistant?c=${assistant.conversationId}` : '/assistant');
    }

    const buttonClass = 'rounded-lg p-2 text-fg-muted transition-colors hover:bg-surface-sunken hover:text-fg';

    return (
        <aside
            ref={panelRef}
            role="complementary"
            aria-label={t('assistant.title')}
            aria-hidden={!open}
            inert={open ? undefined : true}
            className={`fixed inset-y-0 right-0 z-[60] flex w-full flex-col border-l border-line bg-surface shadow-popover transition-transform duration-300 motion-reduce:transition-none sm:w-[26rem] ${open ? 'translate-x-0' : 'translate-x-full'}`}
        >
            <header className="flex items-center gap-2 border-b border-line px-4 py-3">
                <Sparkle size={20} weight="fill" className="text-[var(--accent)]" aria-hidden="true" />
                <h2 className="flex-1 text-h3 font-semibold text-fg">{t('assistant.title')}</h2>
                <button type="button" className={buttonClass} onClick={() => { assistant.reset(); setShowHistory(false); }} aria-label={t('assistant.newChat')} title={t('assistant.newChat')}><NotePencil size={18} aria-hidden="true" /></button>
                <button type="button" className={buttonClass} onClick={() => setShowHistory((v) => !v)} aria-pressed={showHistory} aria-label={t('assistant.history')} title={t('assistant.history')}><ClockCounterClockwise size={18} aria-hidden="true" /></button>
                <button type="button" className={buttonClass} onClick={expand} aria-label={t('assistant.expand')} title={t('assistant.expand')}><ArrowsOutSimple size={18} aria-hidden="true" /></button>
                <button type="button" className={buttonClass} onClick={onClose} aria-label={t('assistant.close')} title={t('assistant.close')}><X size={18} aria-hidden="true" /></button>
            </header>

            {assistant.status && !assistant.status.available ? (
                <p className="m-4 rounded-lg border border-warning-line bg-warning-soft px-3 py-2 text-sm text-warning" role="status">{t('assistant.unavailable')}</p>
            ) : showHistory ? (
                <div className="min-h-0 flex-1 overflow-y-auto">
                    <ConversationList
                        activeId={assistant.conversationId}
                        refreshKey={refreshKey}
                        onOpen={(id) => { assistant.open(id); setShowHistory(false); }}
                        onDeleted={(id) => id === assistant.conversationId && assistant.reset()}
                    />
                </div>
            ) : (
                <AssistantChat assistant={assistant} autoFocus={open} />
            )}
        </aside>
    );
}
