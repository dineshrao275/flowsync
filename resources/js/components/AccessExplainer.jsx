import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from './ui/Card';
import Button from './ui/Button';
import Alert from './ui/Alert';

/** "Why can't they?": asks the server which role, scope or plan module grants or blocks one permission. */
export default function AccessExplainer({ userId, permissions = [] }) {
    const { t } = useTranslation();
    const [slug, setSlug] = useState('');
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    async function check(e) {
        e.preventDefault();
        if (!slug.trim()) return;
        setBusy(true); setError(null);
        try {
            const { data } = await api.get(`/users/${userId}/access`, { params: { check: slug.trim() } });
            setResult(data);
        } catch (err) {
            setResult(null);
            setError(fieldErrors(err).check || t('admin.accessExplainer.error'));
        } finally { setBusy(false); }
    }

    return (
        <Card title={t('admin.accessExplainer.title')} subtitle={t('admin.accessExplainer.subtitle')}>
            <form onSubmit={check} className="flex flex-wrap items-center gap-2">
                <input
                    list="access-permissions" value={slug} onChange={(e) => setSlug(e.target.value)} placeholder={t('admin.accessExplainer.placeholder')}
                    className="min-w-64 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[var(--accent)]"
                />
                <datalist id="access-permissions">
                    {permissions.map((p) => <option key={p.slug} value={p.slug}>{p.name}</option>)}
                </datalist>
                <Button type="submit" loading={busy} disabled={!slug.trim()}>{t('admin.accessExplainer.check')}</Button>
            </form>
            <Alert>{error}</Alert>
            {result && (
                <div className={`mt-3 rounded-lg border p-3 text-sm ${result.allowed ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}>
                    <p className="font-semibold">{result.allowed ? t('admin.accessExplainer.allowed') : t('admin.accessExplainer.notAllowed')}</p>
                    <p className="mt-1">{result.reason}</p>
                    {result.granted_by?.length > 0 && (
                        <ul className="mt-2 list-disc pl-5">
                            {result.granted_by.map((g, i) => <li key={i}>{g.role.name} — {g.via}</li>)}
                        </ul>
                    )}
                    {result.module && <p className="mt-2 text-xs">{t('admin.accessExplainer.planModule')} <code>{result.module.key}</code>: {result.module.available ? t('admin.accessExplainer.included') : t('admin.accessExplainer.notIncluded')}</p>}
                </div>
            )}
        </Card>
    );
}
