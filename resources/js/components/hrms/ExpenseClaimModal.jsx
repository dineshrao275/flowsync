import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
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
    const { t } = useTranslation();
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
        formData.append('title', t('hrms.expenseModal.receiptTitle', { name: file.name }));
        formData.append('file', file);

        try {
            const { data } = await api.post('/hrms/documents', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setLine(index, { receipt_document_id: String(data.document.id) });
            toast.success(t('hrms.expenseModal.receiptAttached'));
        } catch {
            toast.error(t('hrms.expenseModal.receiptFailed'));
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
                toast.success(t('hrms.expenseModal.linesUpdated'));
            } else {
                await api.post('/hrms/expenses/claims', { ...payload, employee_id: Number(employeeId) });
                toast.success(t('hrms.expenseModal.filedDraft'));
            }

            onSaved();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    return (
        <Modal open={open} onClose={onClose} title={claim ? t('hrms.expenseModal.relineTitle', { number: claim.claim_number }) : t('hrms.expenseModal.fileTitle')} size="lg">
            <form onSubmit={save} className="grid gap-3 px-6 py-5">
                {!claim && (
                    <>
                        <div className="grid grid-cols-2 gap-3">
                            <Input label={t('hrms.expenseModal.claimDate')} type="date" value={header.claim_date} error={errors.claim_date} onChange={(e) => setHeader({ ...header, claim_date: e.target.value })} required />
                            <Input label={t('hrms.expenseModal.purpose')} value={header.purpose} error={errors.purpose} onChange={(e) => setHeader({ ...header, purpose: e.target.value })} required />
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <Input label={t('hrms.expenseModal.year')} value={header.period_year} error={errors.period_year} onChange={(e) => setHeader({ ...header, period_year: e.target.value })} required />
                            <Input label={t('hrms.expenseModal.month')} value={header.period_month} error={errors.period_month} onChange={(e) => setHeader({ ...header, period_month: e.target.value })} required />
                            <Input label={t('hrms.expenseModal.currency')} value={header.currency} error={errors.currency} onChange={(e) => setHeader({ ...header, currency: e.target.value })} maxLength={3} />
                        </div>
                    </>
                )}

                {errors.items && <Alert>{errors.items}</Alert>}

                {lines.map((line, index) => (
                    <div key={index} className="rounded-lg border border-gray-200 p-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Select label={t('hrms.expenseModal.lineCategory', { number: index + 1 })} value={line.category_id} onChange={(e) => setLine(index, { category_id: e.target.value })}>
                                <option value="">{t('hrms.expenseModal.adHoc')}</option>
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <Input label={t('hrms.expenseModal.amount')} value={line.amount} onChange={(e) => setLine(index, { amount: e.target.value })} required />
                        </div>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            <Input label={t('hrms.expenseModal.description')} value={line.description} onChange={(e) => setLine(index, { description: e.target.value })} required />
                            <Input label={t('hrms.expenseModal.vendor')} value={line.vendor} onChange={(e) => setLine(index, { vendor: e.target.value })} />
                        </div>
                        <div className="mt-3 flex flex-wrap items-end gap-3">
                            <label className="block text-sm">
                                <span className="mb-1.5 block font-medium text-gray-700">{t('hrms.expenseModal.receipt')}</span>
                                <input
                                    type="file"
                                    disabled={uploading === index || types.length === 0}
                                    onChange={(e) => uploadReceipt(index, e.target.files?.[0])}
                                    className="text-sm text-gray-500"
                                />
                            </label>
                            {line.receipt_document_id !== '' && <span className="pb-1 text-sm text-green-700">{t('hrms.expenseModal.attachedFile', { id: line.receipt_document_id })}</span>}
                            {uploading === index && <span className="pb-1 text-sm text-gray-500">{t('hrms.expenseModal.uploading')}</span>}
                            <label className="flex items-center gap-2 pb-1 text-sm">
                                <input type="checkbox" checked={line.is_billable} onChange={(e) => setLine(index, { is_billable: e.target.checked })} />
                                {t('hrms.expenseModal.billable')}
                            </label>
                            {lines.length > 1 && (
                                <Button type="button" size="sm" variant="danger" onClick={() => setLines((rows) => rows.filter((_, i) => i !== index))}>
                                    {t('hrms.expenseModal.remove')}
                                </Button>
                            )}
                        </div>
                    </div>
                ))}

                <div>
                    <Button type="button" variant="secondary" onClick={() => setLines((rows) => [...rows, { ...emptyLine }])}>
                        {t('hrms.expenseModal.addLine')}
                    </Button>
                </div>

                <div><Button type="submit">{claim ? t('hrms.expenseModal.saveLines') : t('hrms.expenseModal.fileClaim')}</Button></div>
            </form>
        </Modal>
    );
}
