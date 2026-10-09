import { useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Modal from '../ui/Modal';
import Select from '../ui/Select';
import { useToast } from '../../context/ToastContext';

const REASONS = [
    { value: 'lost', label: 'Lost' },
    { value: 'damaged', label: 'Damaged' },
    { value: 'faulty', label: 'Faulty' },
    { value: 'obsolete', label: 'Obsolete' },
];

/**
 * Record that another (available) asset replaces this one. The old asset is
 * closed out - taken back or marked lost, then retired - and, optionally, the
 * replacement is handed straight to whoever held it.
 */
export default function AssetReplaceModal({ asset, candidates, onClose, onDone }) {
    const toast = useToast();
    const [form, setForm] = useState({ replacement_asset_id: '', reason: 'damaged', assign_to_holder: true });
    const [errors, setErrors] = useState({});

    async function submit(e) {
        e.preventDefault();
        setErrors({});

        try {
            await api.post(`/hrms/assets/${asset.id}/replace`, {
                replacement_asset_id: Number(form.replacement_asset_id),
                reason: form.reason,
                assign_to_holder: form.assign_to_holder,
            });
            toast.success('Asset replaced.');
            onDone();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    return (
        <Modal open onClose={onClose} title={`Replace ${asset.asset_code}`}>
            <form onSubmit={submit} className="space-y-3">
                <Select label="Replacement (available assets)" value={form.replacement_asset_id} error={errors.replacement_asset_id} onChange={(e) => setForm({ ...form, replacement_asset_id: e.target.value })}>
                    <option value="">Choose…</option>
                    {candidates.map((c) => <option key={c.id} value={c.id}>{c.asset_code} · {c.name}</option>)}
                </Select>
                <Select label="Why" value={form.reason} error={errors.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })}>
                    {REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                </Select>
                {asset.assignee && (
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.assign_to_holder} onChange={(e) => setForm({ ...form, assign_to_holder: e.target.checked })} />
                        Hand the replacement to {asset.assignee.name}
                    </label>
                )}
                {errors.form && <Alert>{errors.form}</Alert>}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit">Replace</Button>
                </div>
            </form>
        </Modal>
    );
}
