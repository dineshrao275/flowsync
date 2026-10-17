import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Button from '../ui/Button';
import Input from '../ui/Input';
import { formatDateTime } from '../../utils/format';

/** The comment thread on an HR record (expense claim, leave request): read it, add to it, nothing is edited. */
export default function RecordComments({ type, id }) {
    const { t } = useTranslation();
    const [comments, setComments] = useState(null);
    const [body, setBody] = useState('');
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get(`/hrms/records/${type}/${id}/comments`).then(({ data }) => setComments(data.comments)).catch(() => setError(t('hrms.comments.loadFailed')));
    }, [type, id, t]);

    useEffect(() => { if (id) load(); }, [id, load]);

    async function add(e) {
        e.preventDefault();
        if (!body.trim()) return;
        try {
            await api.post(`/hrms/records/${type}/${id}/comments`, { body });
            setBody('');
            load();
        } catch {
            setError(t('hrms.comments.postFailed'));
        }
    }

    return (
        <div className="border-t border-gray-100 pt-3">
            <h4 className="mb-2 text-sm font-semibold text-gray-900">{t('hrms.comments.title')}</h4>
            {error && <p className="mb-2 text-xs text-red-600">{error}</p>}
            {comments && comments.length === 0 && <p className="text-sm text-gray-500">{t('hrms.comments.empty')}</p>}
            <ul className="space-y-2">
                {(comments || []).map((c) => (
                    <li key={c.id} className="text-sm">
                        <span className="font-medium text-gray-800">{c.author || t('hrms.comments.unknown')}</span>
                        <span className="ml-2 text-xs text-gray-500">{formatDateTime(c.created_at)}</span>
                        <p className="whitespace-pre-wrap text-gray-700">{c.body}</p>
                    </li>
                ))}
            </ul>
            <form onSubmit={add} className="mt-3 flex items-end gap-2">
                <div className="flex-1"><Input label={t('hrms.comments.add')} compact value={body} maxLength={2000} onChange={(e) => setBody(e.target.value)} /></div>
                <Button type="submit" size="sm" disabled={!body.trim()}>{t('hrms.comments.post')}</Button>
            </form>
        </div>
    );
}
