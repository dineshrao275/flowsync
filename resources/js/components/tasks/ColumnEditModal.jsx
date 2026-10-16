import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import Input from '../ui/Input';
import { fieldClass } from '../ui/fieldStyles';

const CATEGORIES = ['todo', 'in_progress', 'done'];

/** Shared (project-wide) column settings. Managers only — the server enforces it too. */
export default function ColumnEditModal({ status, onClose, onSave }) {
    const { t } = useTranslation();
    const [form, setForm] = useState({
        name: status.name,
        category: status.category,
        color: status.color || '#94a3b8',
        wip_limit: status.wip_limit ?? '',
    });
    const [saving, setSaving] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        const ok = await onSave(status, {
            name: form.name.trim(),
            category: form.category,
            color: form.color,
            wip_limit: form.wip_limit === '' ? null : Number(form.wip_limit),
        });
        setSaving(false);
        if (ok) onClose();
    }

    return (
        <Modal open onClose={onClose} title={t('tms.columns.editTitle', { name: status.name })}>
            <form onSubmit={submit} className="space-y-4">
                <Input label={t('tms.project.statusName')} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required />
                <div>
                    <label htmlFor="col-category" className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.project.category')}</label>
                    <select id="col-category" className={fieldClass} value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}>
                        {CATEGORIES.map((c) => (
                            <option key={c} value={c}>{t(`tms.columns.category.${c}`)}</option>
                        ))}
                    </select>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="col-color" className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.columns.color')}</label>
                        <input id="col-color" type="color" value={form.color} onChange={(e) => setForm((f) => ({ ...f, color: e.target.value }))} className="h-10 w-full cursor-pointer rounded-lg border border-gray-300 bg-white p-1" />
                    </div>
                    <Input
                        label={t('tms.columns.wipLimit')}
                        type="number"
                        min="1"
                        placeholder={t('tms.columns.noLimit')}
                        value={form.wip_limit}
                        onChange={(e) => setForm((f) => ({ ...f, wip_limit: e.target.value }))}
                    />
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>{t('tms.columns.cancel')}</Button>
                    <Button type="submit" disabled={saving || !form.name.trim()}>{t('tms.columns.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
