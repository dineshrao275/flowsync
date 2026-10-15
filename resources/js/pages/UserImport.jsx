import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
    usePageTitle('Import users');
    const navigate = useNavigate();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [file, setFile] = useState(null);
    const [report, setReport] = useState(null);
    const [roles, setRoles] = useState([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);

    useEffect(() => { setCrumbs([{ label: 'Users', to: '/users' }, { label: 'Import' }]); }, [setCrumbs]);

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
            setError(fieldErrors(e).file || fieldErrors(e).form || 'Could not read that file.');
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
            setError(e?.response?.data?.message || 'Import failed.');
        } finally { setBusy(false); }
    }

    const generated = result?.created?.filter((u) => u.temporary_password) || [];

    return (
        <div className="mx-auto max-w-4xl space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Import users</h2>
                <p className="mt-1 text-sm text-gray-500">Add many people at once from a CSV file.</p>
            </div>

            <Card title="1. Get the format" subtitle="Columns: name, email, roles — and an optional password.">
                <ul className="list-disc space-y-1 pl-5 text-sm text-gray-600">
                    <li><b>roles</b>: role slugs or names; separate several with <code>|</code> (for example <code>editor|viewer</code>).</li>
                    <li><b>password</b>: leave blank to generate one — generated passwords are shown once after import.</li>
                    <li>You can only assign roles whose permissions you hold. Emails must not already exist. Max 500 rows.</li>
                </ul>
                <div className="mt-3 flex gap-2">
                    <Button variant="secondary" onClick={() => downloadCsv('users-import-sample.csv', SAMPLE_ROWS)}>Download sample CSV</Button>
                </div>
                <pre className="mt-3 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{SAMPLE_ROWS.map((r) => r.join(',')).join('\n')}</pre>
            </Card>

            <Card title="2. Upload & check">
                <div className="flex flex-wrap items-center gap-3">
                    <input type="file" accept=".csv,text/csv" onChange={(e) => { setFile(e.target.files?.[0] || null); setReport(null); setResult(null); }} />
                    <Button onClick={preview} disabled={!file} loading={busy}>Check file</Button>
                </div>
                <Alert>{error}</Alert>
            </Card>

            {report && (
                <Card title="3. Review" subtitle={`${report.summary.valid} of ${report.summary.total} rows are ready to import.`}>
                    {roles.length > 0 && <p className="mb-3 text-xs text-gray-500">Roles you may assign: {roles.map((r) => r.slug).join(', ')}</p>}
                    <div className="max-h-96 overflow-auto rounded-lg border border-gray-200">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr><th className="px-3 py-2">Line</th><th className="px-3 py-2">Name</th><th className="px-3 py-2">Email</th><th className="px-3 py-2">Roles</th><th className="px-3 py-2">Result</th></tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {report.rows.map((r) => (
                                    <tr key={r.line} className={r.errors.length ? 'bg-red-50/50' : ''}>
                                        <td className="px-3 py-2 text-gray-500">{r.line}</td>
                                        <td className="px-3 py-2">{r.name}</td>
                                        <td className="px-3 py-2">{r.email}</td>
                                        <td className="px-3 py-2">{r.roles.join(', ')}</td>
                                        <td className="px-3 py-2">
                                            {r.errors.length ? <ul className="text-red-700">{r.errors.map((e) => <li key={e}>{e}</li>)}</ul> : <span className="text-emerald-700">Ready</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        {report.summary.invalid === 0 ? (
                            <Button onClick={() => commit(false)} loading={busy}>Import {report.summary.valid} users</Button>
                        ) : (
                            <>
                                <Button variant="secondary" onClick={() => commit(true)} loading={busy} disabled={report.summary.valid === 0}>
                                    Import the {report.summary.valid} valid rows only
                                </Button>
                                <span className="self-center text-sm text-gray-500">or fix the file and check again.</span>
                            </>
                        )}
                    </div>
                </Card>
            )}

            {result && (
                <Card title="Done" subtitle={result.message}>
                    {generated.length > 0 && (
                        <>
                            <Alert type="info">These generated passwords are shown only once. Download them now and share them securely.</Alert>
                            <Button className="mt-3" variant="secondary" onClick={() => downloadCsv('imported-users-passwords.csv', [['name', 'email', 'temporary_password'], ...generated.map((u) => [u.name, u.email, u.temporary_password])])}>
                                Download passwords CSV
                            </Button>
                        </>
                    )}
                    <div className="mt-4"><Button onClick={() => navigate('/users')}>Back to users</Button></div>
                </Card>
            )}
        </div>
    );
}
