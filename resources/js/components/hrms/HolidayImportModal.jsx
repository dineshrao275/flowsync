import { useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Modal from '../ui/Modal';
import { Table, Th, Td } from '../ui/Table';
import { useToast } from '../../context/ToastContext';

/**
 * Import holidays from a CSV or an .ics calendar into the selected calendar:
 * validate first (new / duplicate / invalid per row), then import the new ones.
 */
export default function HolidayImportModal({ calendar, onClose, onDone }) {
    const toast = useToast();
    const [file, setFile] = useState(null);
    const [report, setReport] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const base = `/hrms/holidays/calendars/${calendar.id}/import`;

    function body(skip = false) {
        const data = new FormData();
        data.append('file', file);
        if (skip) data.append('skip_invalid', '1');

        return data;
    }

    async function validate() {
        setBusy(true);
        setError(null);
        setReport(null);

        try {
            const { data } = await api.post(`${base}/preview`, body(), { headers: { 'Content-Type': 'multipart/form-data' } });
            setReport(data);
        } catch (err) {
            setError(fieldErrors(err).file ?? 'Unable to read that file.');
        } finally {
            setBusy(false);
        }
    }

    async function commit() {
        setBusy(true);

        try {
            const { data } = await api.post(base, body(true), { headers: { 'Content-Type': 'multipart/form-data' } });
            toast.success(data.message);
            onDone();
        } catch (err) {
            setError(fieldErrors(err).file ?? 'The import failed.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal open onClose={onClose} title={`Import into ${calendar.name}`}>
            <div className="space-y-3">
                {error && <Alert>{error}</Alert>}
                <p className="text-sm text-gray-500">Upload a .csv (name, date, type, is_recurring) or an .ics calendar. Existing holidays with the same name and date are skipped.</p>
                <input type="file" accept=".csv,.txt,.ics,text/csv,text/calendar" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setReport(null); }} />
                {report && (
                    <>
                        <p className="text-sm"><strong>{report.new}</strong> new, <strong>{report.duplicates}</strong> already there, <strong>{report.invalid}</strong> invalid</p>
                        <div className="max-h-56 overflow-y-auto">
                            <Table>
                                <thead><tr><Th>Date</Th><Th>Name</Th><Th>Result</Th></tr></thead>
                                <tbody>
                                    {report.rows.map((row, i) => (
                                        <tr key={i}><Td>{row.date}</Td><Td>{row.name}</Td><Td>{row.error ?? row.status}</Td></tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </>
                )}
                <div className="flex items-center justify-between gap-2">
                    <a className="text-sm text-indigo-600 underline" href="/api/hrms/holidays/import/sample">Sample CSV</a>
                    <div className="flex gap-2">
                        <Button variant="secondary" onClick={validate} disabled={!file || busy}>Validate</Button>
                        <Button onClick={commit} disabled={!report || report.new === 0 || busy}>Import {report?.new ?? 0}</Button>
                    </div>
                </div>
            </div>
        </Modal>
    );
}
