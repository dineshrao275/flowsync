import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Modal from '../ui/Modal';
import Select from '../ui/Select';
import { Table, Th, Td } from '../ui/Table';
import { useToast } from '../../context/ToastContext';

const TARGETS = [
    { value: 'active', label: 'Active' },
    { value: 'probation', label: 'Probation' },
    { value: 'on_notice', label: 'On notice' },
    { value: 'suspended', label: 'Suspended' },
];

/**
 * Bulk employee operations. "import" is the validate → preview → commit CSV
 * flow (a file is validated first; only then can the valid rows be imported).
 * "status" picks people, previews the per-person outcome with a dry run, and
 * applies it. Terminal states are not offered: ending an employment is
 * decided per person through offboarding.
 */
export default function EmployeeBulkModal({ mode, onClose, onDone }) {
    const toast = useToast();
    const [file, setFile] = useState(null);
    const [report, setReport] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const [people, setPeople] = useState([]);
    const [picked, setPicked] = useState([]);
    const [form, setForm] = useState({ to: 'active', effective_date: '', reason: '' });
    const [preview, setPreview] = useState(null);

    useEffect(() => {
        if (mode !== 'status') return;

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setPeople((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name, code: e.employee_code, status: e.status }))))
            .catch(() => setPeople([]));
    }, [mode]);

    function body(skipInvalid = false) {
        const data = new FormData();
        data.append('file', file);
        if (skipInvalid) data.append('skip_invalid', '1');

        return data;
    }

    async function runPreview() {
        setBusy(true);
        setError(null);
        setReport(null);

        try {
            const { data } = await api.post('/hrms/employees/import/preview', body(), { headers: { 'Content-Type': 'multipart/form-data' } });
            setReport(data);
        } catch (err) {
            setError(fieldErrors(err).file ?? 'Unable to read that file.');
        } finally {
            setBusy(false);
        }
    }

    async function commit() {
        setBusy(true);
        setError(null);

        try {
            const { data } = await api.post('/hrms/employees/import', body(true), { headers: { 'Content-Type': 'multipart/form-data' } });
            toast.success(data.message);
            onDone();
        } catch (err) {
            const errors = fieldErrors(err);
            setError(errors.file ?? errors.form ?? 'The import failed.');
        } finally {
            setBusy(false);
        }
    }

    async function runStatus(dryRun) {
        setBusy(true);
        setError(null);

        try {
            const { data } = await api.post('/hrms/employees/bulk-status', {
                employee_ids: picked,
                to: form.to,
                ...(form.effective_date ? { effective_date: form.effective_date } : {}),
                ...(form.reason ? { reason: form.reason } : {}),
                dry_run: dryRun,
            });

            if (dryRun) {
                setPreview(data.results);
            } else {
                toast.success(`${data.changed} employee(s) updated.`);
                onDone();
            }
        } catch (err) {
            const errors = fieldErrors(err);
            setError(errors.to ?? errors.employee_ids ?? errors.form ?? 'The change failed.');
        } finally {
            setBusy(false);
        }
    }

    function togglePerson(id) {
        setPicked((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));
        setPreview(null);
    }

    return (
        <Modal open onClose={onClose} title={mode === 'import' ? 'Import employees from CSV' : 'Change status in bulk'}>
            <div className="space-y-3">
                {error && <Alert>{error}</Alert>}

                {mode === 'import' ? (
                    <>
                        <p className="text-sm text-gray-500">
                            Columns: name (required), personal_email, phone, joining_date, department, designation, location, employment_type, work_mode, manager_code.
                            Logins are not created.
                        </p>
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e) => {
                                setFile(e.target.files?.[0] ?? null);
                                setReport(null);
                            }}
                        />
                        {report && (
                            <>
                                <p className="text-sm">
                                    <strong>{report.valid}</strong> valid, <strong>{report.invalid}</strong> with errors
                                    {report.free_seats !== null && ` · ${report.free_seats} free employee seat(s) on your plan`}
                                </p>
                                {report.invalid > 0 && (
                                    <Table>
                                        <thead><tr><Th>Line</Th><Th>Name</Th><Th>Problems</Th></tr></thead>
                                        <tbody>
                                            {report.rows.filter((r) => r.errors.length > 0).map((r) => (
                                                <tr key={r.line}><Td>{r.line}</Td><Td>{r.name}</Td><Td>{r.errors.join(' ')}</Td></tr>
                                            ))}
                                        </tbody>
                                    </Table>
                                )}
                            </>
                        )}
                        <div className="flex justify-between gap-2">
                            <a className="text-sm text-indigo-600 underline" href="/api/hrms/employees/import/sample">Download sample</a>
                            <div className="flex gap-2">
                                <Button variant="secondary" onClick={runPreview} disabled={!file || busy}>Validate</Button>
                                <Button onClick={commit} disabled={!report || report.valid === 0 || busy}>
                                    Import {report ? report.valid : 0} valid row(s)
                                </Button>
                            </div>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <Select label="New status" value={form.to} onChange={(e) => { setForm({ ...form, to: e.target.value }); setPreview(null); }}>
                                {TARGETS.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                            </Select>
                            <Input type="date" label="Effective date" value={form.effective_date} onChange={(e) => setForm({ ...form, effective_date: e.target.value })} />
                            <Input label="Reason" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
                        </div>
                        <div className="max-h-56 overflow-y-auto rounded-lg border border-gray-200 p-2">
                            {people.map((p) => (
                                <label key={p.id} className="flex items-center gap-2 py-0.5 text-sm">
                                    <input type="checkbox" checked={picked.includes(p.id)} onChange={() => togglePerson(p.id)} />
                                    <span>{p.name}</span>
                                    <span className="font-mono text-xs text-gray-400">{p.code}</span>
                                </label>
                            ))}
                        </div>
                        {preview && (
                            <ul className="text-sm">
                                {preview.map((r) => (
                                    <li key={r.id}>{r.name}: <span className="font-medium">{r.outcome.replace('_', ' ')}</span></li>
                                ))}
                            </ul>
                        )}
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => runStatus(true)} disabled={picked.length === 0 || busy}>Preview</Button>
                            <Button onClick={() => runStatus(false)} disabled={!preview || busy}>Apply to {picked.length}</Button>
                        </div>
                    </>
                )}
            </div>
        </Modal>
    );
}
