import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Trans, useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import { useEffect } from 'react';

const SAMPLE_ROWS = [
    ['name', 'email', 'roles', 'password'],
    ['Jane Doe', 'jane.doe@example.com', 'editor', ''],
    ['John Roe', 'john.roe@example.com', 'editor|viewer', 'ChangeMe-2026'],
];

function downloadCsv(filename, rows) {
    const text = rows.map((r) => r.map((c) => (/[",\n]/.test(String(c)) ? `"${String(c).replace(/"/g, '""')}"` : c)).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([text], { type: 'text/csv' }));
    const a = Object.assign(document.createElement('a'), { href: url, download: filename });
    a.click();
    URL.revokeObjectURL(url);
}

/** Preview → import. Every row is validated server-side with the same privilege ceiling as creating a user by hand. */
export default function UserImport() {
    const { t } = useTranslation();
    usePageTitle(t('admin.userImport.pageTitle'));
    const navigate = useNavigate();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [file, setFile] = useState(null);
    const [report, setReport] = useState(null);
    const [roles, setRoles] = useState([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);

    useEffect(() => { setCrumbs([{ label: t('admin.userImport.usersCrumb'), to: '/users' }, { label: t('admin.userImport.crumb') }]); }, [setCrumbs, t]);

    function form(extra = {}) {
        const body = new FormData();
        body.append('file', file);
        Object.entries(extra).forEach(([k, v]) => body.append(k, v));
        return body;
    }

    async function preview() {
        setBusy(true); setError(null); setResult(null);
        try {
            const { data } = await api.post('/users/import/preview', form(), { headers: { 'Content-Type': 'multipart/form-data' } });
            setReport(data);
            setRoles(data.available_roles || []);
        } catch (e) {
            setReport(null);
            setError(fieldErrors(e).file || fieldErrors(e).form || t('admin.userImport.readFailed'));
        } finally { setBusy(false); }
    }

    async function commit(skipInvalid) {
        setBusy(true); setError(null);
        try {
            const { data } = await api.post('/users/import', form(skipInvalid ? { skip_invalid: 1 } : {}), { headers: { 'Content-Type': 'multipart/form-data' } });
            setResult(data);
            setReport(null);
            toast.success(data.message);
        } catch (e) {
            if (e?.response?.data?.rows) setReport(e.response.data);
            setError(e?.response?.data?.message || t('admin.userImport.importFailed'));
        } finally { setBusy(false); }
    }

    const generated = result?.created?.filter((u) => u.temporary_password) || [];

    return (
        <div className="mx-auto max-w-4xl space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('admin.userImport.title')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('admin.userImport.subtitle')}</p>
            </div>

            <Card title={t('admin.userImport.step1Title')} subtitle={t('admin.userImport.step1Subtitle')}>
                <ul className="list-disc space-y-1 pl-5 text-sm text-gray-600">
                    <li><Trans i18nKey="admin.userImport.rolesFormat" components={[<b key="0" />, <code key="1" />, <code key="2" />]} /></li>
                    <li><Trans i18nKey="admin.userImport.passwordFormat" components={[<b key="0" />]} /></li>
                    <li>{t('admin.userImport.caps')}</li>
                </ul>
                <div className="mt-3 flex gap-2">
                    <Button variant="secondary" onClick={() => downloadCsv('users-import-sample.csv', SAMPLE_ROWS)}>{t('admin.userImport.downloadSample')}</Button>
                </div>
                <pre className="mt-3 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{SAMPLE_ROWS.map((r) => r.join(',')).join('\n')}</pre>
            </Card>

            <Card title={t('admin.userImport.step2Title')}>
                <div className="flex flex-wrap items-center gap-3">
                    <input type="file" accept=".csv,text/csv" onChange={(e) => { setFile(e.target.files?.[0] || null); setReport(null); setResult(null); }} />
                    <Button onClick={preview} disabled={!file} loading={busy}>{t('admin.userImport.checkFile')}</Button>
                </div>
                <Alert>{error}</Alert>
            </Card>

            {report && (
                <Card title={t('admin.userImport.step3Title')} subtitle={t('admin.userImport.step3Subtitle', { valid: report.summary.valid, total: report.summary.total })}>
                    {roles.length > 0 && <p className="mb-3 text-xs text-gray-500">{t('admin.userImport.rolesYouMayAssign', { roles: roles.map((r) => r.slug).join(', ') })}</p>}
                    <div className="max-h-96 overflow-auto rounded-lg border border-gray-200">
                        <div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr><th scope="col" className="px-3 py-2">{t('admin.userImport.thLine')}</th><th scope="col" className="px-3 py-2">{t('admin.userImport.thName')}</th><th scope="col" className="px-3 py-2">{t('admin.userImport.thEmail')}</th><th scope="col" className="px-3 py-2">{t('admin.userImport.thRoles')}</th><th scope="col" className="px-3 py-2">{t('admin.userImport.thResult')}</th></tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {report.rows.map((r) => (
                                    <tr key={r.line} className={r.errors.length ? 'bg-red-50/50' : ''}>
                                        <td className="px-3 py-2 text-gray-500">{r.line}</td>
                                        <td className="px-3 py-2">{r.name}</td>
                                        <td className="px-3 py-2">{r.email}</td>
                                        <td className="px-3 py-2">{r.roles.join(', ')}</td>
                                        <td className="px-3 py-2">
                                            {r.errors.length ? <ul className="text-red-700">{r.errors.map((e) => <li key={e}>{e}</li>)}</ul> : <span className="text-emerald-700">{t('admin.userImport.ready')}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table></div>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        {report.summary.invalid === 0 ? (
                            <Button onClick={() => commit(false)} loading={busy}>{t('admin.userImport.importUsers', { count: report.summary.valid })}</Button>
                        ) : (
                            <>
                                <Button variant="secondary" onClick={() => commit(true)} loading={busy} disabled={report.summary.valid === 0}>
                                    {t('admin.userImport.importValidOnly', { valid: report.summary.valid })}
                                </Button>
                                <span className="self-center text-sm text-gray-500">{t('admin.userImport.orFix')}</span>
                            </>
                        )}
                    </div>
                </Card>
            )}

            {result && (
                <Card title={t('admin.userImport.doneTitle')} subtitle={result.message}>
                    {generated.length > 0 && (
                        <>
                            <Alert type="info">{t('admin.userImport.generatedNotice')}</Alert>
                            <Button className="mt-3" variant="secondary" onClick={() => downloadCsv('imported-users-passwords.csv', [['name', 'email', 'temporary_password'], ...generated.map((u) => [u.name, u.email, u.temporary_password])])}>
                                {t('admin.userImport.downloadPasswords')}
                            </Button>
                        </>
                    )}
                    <div className="mt-4"><Button onClick={() => navigate('/users')}>{t('admin.userImport.backToUsers')}</Button></div>
                </Card>
            )}
        </div>
    );
}
