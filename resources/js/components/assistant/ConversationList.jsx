import { useTranslation } from 'react-i18next';
import { Trash } from '@phosphor-icons/react';
import api from '../../services/api';
import { useResource } from '../../hooks/useResource';
import { formatRelativeTime } from '../../utils/format';

export default function ConversationList({ activeId, onOpen, onDeleted, refreshKey }) {
    const { t } = useTranslation();
    const { data, loading, error, reload } = useResource((signal) => api.get('/ai/conversations', { signal }), [refreshKey]);
    const conversations = data?.data?.conversations ?? [];

    async function remove(event, id) {
        event.stopPropagation();
        try {
            await api.delete(`/ai/conversations/${id}`);
            onDeleted?.(id);
            reload();
        } catch {
            /* the list stays as it was; the next reload shows the truth */
        }
    }

    if (loading && !data) return <p className="px-4 py-3 text-sm text-fg-muted" role="status">{t('assistant.loadingHistory')}</p>;
    if (error) return <p className="px-4 py-3 text-sm text-danger-fg">{t('assistant.errors.load')}</p>;
    if (conversations.length === 0) return <p className="px-4 py-3 text-sm text-fg-muted">{t('assistant.noHistory')}</p>;

    return (
        <ul className="divide-y divide-line" aria-label={t('assistant.history')}>
            {conversations.map((c) => (
                <li key={c.id} className={`flex items-center gap-1 ${c.id === activeId ? 'bg-surface-sunken' : ''}`}>
                    <button type="button" onClick={() => onOpen(c.id)} className="min-w-0 flex-1 px-4 py-2.5 text-left">
                        <span className="block truncate text-sm font-medium text-fg">{c.title || t('assistant.untitled')}</span>
                        <span className="block text-caption text-fg-subtle">{c.last_message_at ? formatRelativeTime(c.last_message_at) : ''}</span>
                    </button>
                    <button type="button" onClick={(e) => remove(e, c.id)} aria-label={t('assistant.deleteConversation', { title: c.title || t('assistant.untitled') })} className="mr-2 rounded p-1.5 text-fg-subtle hover:bg-surface-sunken hover:text-danger-fg">
                        <Trash size={16} aria-hidden="true" />
                    </button>
                </li>
            ))}
        </ul>
    );
}
