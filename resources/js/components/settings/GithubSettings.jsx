import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Card from '../ui/Card';
import Button from '../ui/Button';
import ContentSkeleton from '../ui/ContentSkeleton';

/** Link GitHub pull requests and commits to tasks by key (ABC-123). The signing secret is shown once. */
export default function GithubSettings() {
    const { t } = useTranslation();
    const toast = useToast();
    const [state, setState] = useState(null);
    const [secret, setSecret] = useState(null);

    useEffect(() => { api.get('/github-integration').then(({ data }) => setState(data)).catch(() => setState(false)); }, []);

    async function save(enabled, rotate = false) {
        const { data } = await api.put('/github-integration', { enabled, rotate });
        setState(data);
        setSecret(data.secret || null);
        toast.success(t('admin.github.saved'));
    }

    if (state === false) return null;
    if (!state) return <Card><ContentSkeleton size="sm" /></Card>;

    return (
        <Card title={t('admin.github.title')} subtitle={t('admin.github.subtitle')}>
            <div className="space-y-3 text-sm text-gray-700">
                <p>{t('admin.github.status')}: <strong>{state.enabled ? t('admin.github.on') : t('admin.github.off')}</strong></p>
                {state.enabled && <p>{t('admin.github.payload')} <code className="rounded bg-gray-100 px-1.5 py-0.5 text-xs">{state.payload_url}</code> ({t('admin.github.contentType')})</p>}
                {secret && <p className="rounded-md bg-amber-50 p-3 text-amber-900">{t('admin.github.secretOnce')} <code className="break-all font-mono text-xs">{secret}</code></p>}
                <div className="flex gap-2">
                    {!state.enabled ? <Button onClick={() => save(true)}>{t('admin.github.enable')}</Button> : (
                        <>
                            <Button variant="secondary" onClick={() => save(true, true)}>{t('admin.github.rotate')}</Button>
                            <Button variant="danger" onClick={() => { setSecret(null); save(false); }}>{t('admin.github.disable')}</Button>
                        </>
                    )}
                </div>
            </div>
        </Card>
    );
}
