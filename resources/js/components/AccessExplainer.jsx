import { useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from './ui/Card';
import Button from './ui/Button';
import Alert from './ui/Alert';

/** "Why can't they?": asks the server which role, scope or plan module grants or blocks one permission. */
export default function AccessExplainer({ userId, permissions = [] }) {
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
            setError(fieldErrors(err).check || 'Could not check that permission.');
        } finally { setBusy(false); }
    }

    return (
        <Card title="Why can't they?" subtitle="Pick a permission to see what grants or blocks it for this user.">
            <form onSubmit={check} className="flex flex-wrap items-center gap-2">
                <input
                    list="access-permissions" value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="e.g. hrms.payroll.view"
                    className="min-w-64 flex-1 rounded-lg border border-gray-200 dark:border-[#2F3A4C] bg-white dark:bg-[#161B26] text-gray-900 dark:text-[#F3F4F6] placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:border-[var(--accent)] focus:ring-1 focus:ring-[var(--accent)]/30 rounded-lg"
                />
                <datalist id="access-permissions">
                    {permissions.map((p) => <option key={p.slug} value={p.slug}>{p.name}</option>)}
                </datalist>
                <Button type="submit" loading={busy} disabled={!slug.trim()}>Check</Button>
            </form>
            <Alert>{error}</Alert>
            {result && (
                <div className={`mt-3 rounded-lg border p-3 text-sm ${result.allowed ? 'border-emerald-200 bg-emerald-50 dark:text-emerald-900 dark:bg-emerald-900/10' : 'border-amber-200 bg-amber-50 dark:text-amber-900 dark:bg-amber-900/10'}`}>
                    <p className="font-semibold">{result.allowed ? 'Allowed' : 'Not allowed'}</p>
                    <p className="mt-1">{result.reason}</p>
                    {result.granted_by?.length > 0 && (
                        <ul className="mt-2 list-disc pl-5">
                            {result.granted_by.map((g, i) => <li key={i}>{g.role.name} — {g.via}</li>)}
                        </ul>
                    )}
                    {result.module && <p className="mt-2 text-xs">Plan module <code>{result.module.key}</code>: {result.module.available ? 'included' : 'not included'}</p>}
                </div>
            )}
        </Card>
    );
}
