import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { NotePencil } from '@phosphor-icons/react';
import AssistantChat from '../components/assistant/AssistantChat';
import ConversationList from '../components/assistant/ConversationList';
import useAssistant from '../hooks/useAssistant';
import { useActiveProduct } from '../context/ProductContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import Button from '../components/ui/Button';

/** The expanded assistant: history beside a full-height conversation, for longer work than the side panel suits. */
export default function Assistant() {
    const { t } = useTranslation();
    const { activeProduct } = useActiveProduct();
    const [params, setParams] = useSearchParams();
    const [refreshKey, setRefreshKey] = useState(0);
    const assistant = useAssistant({ context: { route: '/assistant', product: activeProduct } });
    const { open, busy, conversationId } = assistant;
    const wanted = params.get('c');

    useSetCrumbs([{ label: t('assistant.title') }]);

    useEffect(() => {
        if (wanted && Number(wanted) !== conversationId) open(Number(wanted));
        // Only the URL decides which conversation to load; `open` is stable.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [wanted]);

    useEffect(() => {
        if (!busy) setRefreshKey((k) => k + 1);
    }, [busy]);

    return (
        <div className="grid h-[calc(100vh-11rem)] min-h-[28rem] gap-4 lg:grid-cols-[18rem_1fr]">
            <aside className="flex min-h-0 flex-col overflow-hidden rounded-xl border border-line bg-surface" aria-label={t('assistant.history')}>
                <div className="flex items-center justify-between border-b border-line px-4 py-3">
                    <h2 className="text-sm font-semibold text-fg">{t('assistant.history')}</h2>
                    <Button size="sm" variant="secondary" onClick={() => { assistant.reset(); setParams({}); }}><NotePencil size={16} aria-hidden="true" />{t('assistant.newChat')}</Button>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto">
                    <ConversationList activeId={conversationId} refreshKey={refreshKey} onOpen={(id) => setParams({ c: String(id) })} onDeleted={(id) => id === conversationId && (assistant.reset(), setParams({}))} />
                </div>
            </aside>
            <section className="flex min-h-0 flex-col overflow-hidden rounded-xl border border-line bg-surface" aria-label={t('assistant.title')}>
                {assistant.status && !assistant.status.available
                    ? <p className="m-4 text-sm text-warning" role="status">{t('assistant.unavailable')}</p>
                    : <AssistantChat assistant={assistant} autoFocus />}
            </section>
        </div>
    );
}
