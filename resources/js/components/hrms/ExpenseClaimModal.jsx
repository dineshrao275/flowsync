import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Select from '../ui/Select';
import Modal from '../ui/Modal';
import { useToast } from '../../context/ToastContext';

const emptyLine = { category_id: '', description: '', amount: '', spent_at: '', vendor: '', receipt_document_id: '', is_billable: false };

/**
 * File (or re-line) a claim: header fields plus a line-item editor where
 * each row picks a category, prices money, and optionally attaches a
 * receipt uploaded straight into the employee's documents.
 *
 * Receipts upload first, lines reference after: a line cannot point at a
 * file that failed to store, and the file input resets per upload so the
 * same picker files one receipt per line without stale state.
 */
export default function ExpenseClaimModal({ open, onClose, onSaved, employeeId, categories, types, claim = null }) {
    const toast = useToast();
    const [header, setHeader] = useState({ claim_date: '', period_year: String(new Date().getFullYear()), period_month: String(new Date().getMonth() + 1), purpose: '', description: '', currency: 'USD' });
    const [lines, setLines] = useState([{ ...emptyLine }]);
    const [errors, setErrors] = useState({});
    const [uploading, setUploading] = useState(null);

    useEffect(() => {
        if (!open) return;

        if (claim) {
            setHeader({
                claim_date: claim.claim_date ?? '',
                period_year: String(claim.period_year ?? new Date().getFullYear()),
                period_month: String(claim.period_month ?? new Date().getMonth() + 1),
                purpose: claim.purpose ?? '',
                description: claim.description ?? '',
                currency: claim.currency ?? 'USD',
            });
            setLines((claim.items ?? []).map((item) => ({
                category_id: item.category_id ? String(item.category_id) : '',
                description: item.description ?? '',
                amount: item.amount ?? '',
                spent_at: item.spent_at ?? '',
                vendor: item.vendor ?? '',
                receipt_document_id: item.receipt_document_id ? String(item.receipt_document_id) : '',
                is_billable: !!item.is_billable,
            })));
        } else {
            setHeader({ claim_date: '', period_year: String(new Date().getFullYear()), period_month: String(new Date().getMonth() + 1), purpose: '', description: '', currency: 'USD' });
            setLines([{ ...emptyLine }]);
        }

        setErrors({});
    }, [open, claim]);

    function setLine(index, patch) {
        setLines((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    async function uploadReceipt(index, file) {
        if (!file) return;

        setUploading(index);

        const formData = new FormData();
        formData.append('employee_id', employeeId);
        formData.append('document_type_id', types[0]?.id);
        formData.append('title', `Receipt — ${file.name}`);
        formData.append('file', file);

        try {
            const { data } = await api.post('/hrms/documents', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setLine(index, { receipt_document_id: String(data.document.id) });
            toast.success('Receipt attached.');
        } catch {
            toast.error('That receipt did not upload.');
        } finally {
            setUploading(null);
        }
    }

    async function save(e) {
        e.preventDefault();
        setErrors({});

        const payload = {
            ...header,
            period_year: Number(header.period_year),
            period_month: Number(header.period_month),
            items: lines.map((line) => ({
                ...line,
                category_id: line.category_id === '' ? null : Number(line.category_id),
                receipt_document_id: line.receipt_document_id === '' ? null : Number(line.receipt_document_id),
                spent_at: line.spent_at || null,
                vendor: line.vendor || null,
            })),
        };

        try {
            if (claim) {
                await api.put(`/hrms/expenses/claims/${claim.id}/items`, { items: payload.items });
                toast.success('Claim lines updated.');
            } else {
                await api.post('/hrms/expenses/claims', { ...payload, employee_id: Number(employeeId) });
                toast.success('Claim filed as a draft.');
            }

            onSaved();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    return (
        <Modal open={open} onClose={onClose} title={claim ? `Re-line ${claim.claim_number}` : 'File a claim'} size="lg">
            <form onSubmit={save} className="grid gap-3">
                {!claim && (
                    <>
                        <div className="grid grid-cols-2 gap-3">
                            <Input label="Claim date" type="date" value={header.claim_date} error={errors.claim_date} onChange={(e) => setHeader({ ...header, claim_date: e.target.value })} required />
                            <Input label="Purpose" value={header.purpose} error={errors.purpose} onChange={(e) => setHeader({ ...header, purpose: e.target.value })} required />
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <Input label="Year" value={header.period_year} error={errors.period_year} onChange={(e) => setHeader({ ...header, period_year: e.target.value })} required />
                            <Input label="Month" value={header.period_month} error={errors.period_month} onChange={(e) => setHeader({ ...header, period_month: e.target.value })} required />
                            <Input label="Currency" value={header.currency} error={errors.currency} onChange={(e) => setHeader({ ...header, currency: e.target.value })} maxLength={3} />
                        </div>
                    </>
                )}

                {errors.items && <Alert>{errors.items}</Alert>}

                {lines.map((line, index) => (
                    <div key={index} className="rounded-xl border border-[var(--border-hairline)] dark:border-[#2F3A4C] bg-[var(--surface-elevated)] dark:bg-[#1E2638] p-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Select label={`Line ${index + 1} category`} value={line.category_id} onChange={(e) => setLine(index, { category_id: e.target.value })}>
                                <option value="">Ad-hoc (no head)…</option>
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <Input label="Amount" value={line.amount} onChange={(e) => setLine(index, { amount: e.target.value })} required />
                        </div>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            <Input label="Description" value={line.description} onChange={(e) => setLine(index, { description: e.target.value })} required />
                            <Input label="Vendor" value={line.vendor} onChange={(e) => setLine(index, { vendor: e.target.value })} />
                        </div>
                        <div className="mt-3 flex flex-wrap items-end gap-3">
                            <label className="block text-sm">
                                <span className="text-[#1C1917] dark:text-[#F8FAFC] mb-1.5 block font-medium">Receipt</span>
                                <input
                                    type="file"
                                    disabled={uploading === index || types.length === 0}
                                    onChange={(e) => uploadReceipt(index, e.target.files?.[0])}
                                    className="text-sm text-gray-500"
                                />
                            </label>
                            {line.receipt_document_id !== '' && <span className="pb-1 text-sm text-green-700">Attached file #{line.receipt_document_id}</span>}
                            {uploading === index && <span className="pb-1 text-sm text-gray-500">Uploading…</span>}
                            <label className="flex items-center gap-2 pb-1 text-sm">
                                <input type="checkbox" checked={line.is_billable} onChange={(e) => setLine(index, { is_billable: e.target.checked })} />
                                Billable
                            </label>
                            {lines.length > 1 && (
                                <Button type="button" size="sm" variant="danger" onClick={() => setLines((rows) => rows.filter((_, i) => i !== index))}>
                                    Remove
                                </Button>
                            )}
                        </div>
                    </div>
                ))}

                <div>
                    <Button type="button" variant="secondary" onClick={() => setLines((rows) => [...rows, { ...emptyLine }])}>
                        Add a line
                    </Button>
                </div>

                <div><Button type="submit">{claim ? 'Save lines' : 'File claim'}</Button></div>
            </form>
        </Modal>
    );
}
