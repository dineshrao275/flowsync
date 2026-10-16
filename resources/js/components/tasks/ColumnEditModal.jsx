import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import Input from '../ui/Input';

const CATEGORIES = ['todo', 'in_progress', 'done'];
const SWATCHES = ['#94a3b8', '#3b82f6', '#06b6d4', '#10b981', '#84cc16', '#f59e0b', '#f97316', '#ef4444', '#ec4899', '#8b5cf6'];

/** Shared (project-wide) column settings. Managers only — the server enforces it too. */
export default function ColumnEditModal({ status, onClose, onSave }) {
    const { t } = useTranslation();
    const [form, setForm] = useState({
        name: status.name,
        category: status.category,
        color: status.color || SWATCHES[0],
        wip_limit: status.wip_limit ?? '',
    });
    const [saving, setSaving] = useState(false);
    const set = (patch) => setForm((f) => ({ ...f, ...patch }));

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
        <Modal open onClose={onClose} size="sm" title={t('tms.columns.editTitle', { name: status.name })} subtitle={t('tms.columns.editSubtitle')}>
            <form onSubmit={submit}>
                <div className="space-y-6 px-6 py-5">
                    <Input label={t('tms.project.statusName')} value={form.name} onChange={(e) => set({ name: e.target.value })} required />

                    <fieldset>
                        <legend className="mb-2 text-sm font-medium text-gray-700">{t('tms.project.category')}</legend>
                        <div role="radiogroup" className="grid grid-cols-3 gap-2">
                            {CATEGORIES.map((c) => {
                                const active = form.category === c;
                                return (
                                    <button
                                        key={c}
                                        type="button"
                                        role="radio"
                                        aria-checked={active}
                                        onClick={() => set({ category: c })}
                                        className={`rounded-lg border px-2 py-2.5 text-center text-sm font-medium transition ${
                                            active
                                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
                                                : 'border-line-input bg-white text-gray-700 hover:bg-gray-50'
                                        }`}
                                    >
                                        {t(`tms.columns.category.${c}`)}
                                    </button>
                                );
                            })}
                        </div>
                        <p className="mt-1.5 text-caption text-fg-muted">{t('tms.columns.categoryHint')}</p>
                    </fieldset>

                    <fieldset>
                        <legend className="mb-2 text-sm font-medium text-gray-700">{t('tms.columns.color')}</legend>
                        <div className="flex flex-wrap items-center gap-2">
                            {SWATCHES.map((c) => (
                                <button
                                    key={c}
                                    type="button"
                                    aria-label={c}
                                    aria-pressed={form.color.toLowerCase() === c}
                                    onClick={() => set({ color: c })}
                                    className={`h-7 w-7 rounded-full ring-offset-2 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)] ${
                                        form.color.toLowerCase() === c ? 'ring-2 ring-gray-900' : 'hover:scale-110'
                                    }`}
                                    style={{ backgroundColor: c }}
                                />
                            ))}
                            <label className="relative ml-1 flex h-7 w-7 cursor-pointer items-center justify-center overflow-hidden rounded-full border border-dashed border-gray-400 text-xs text-gray-500" title={t('tms.columns.customColor')}>
                                <span aria-hidden="true">+</span>
                                <span className="sr-only">{t('tms.columns.customColor')}</span>
                                <input type="color" value={form.color} onChange={(e) => set({ color: e.target.value })} className="absolute inset-0 cursor-pointer opacity-0" />
                            </label>
                        </div>
                    </fieldset>

                    <div>
                        <Input
                            label={t('tms.columns.wipLimit')}
                            type="number"
                            min="1"
                            placeholder={t('tms.columns.noLimit')}
                            value={form.wip_limit}
                            onChange={(e) => set({ wip_limit: e.target.value })}
                        />
                        <p className="mt-1.5 text-caption text-fg-muted">{t('tms.columns.wipHint')}</p>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border border-line bg-gray-50 px-3 py-2 text-sm text-gray-700" aria-hidden="true">
                        <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: form.color }} />
                        <span className="truncate font-semibold">{form.name || '—'}</span>
                        {form.wip_limit !== '' && <span className="ml-auto text-xs text-gray-500">{t('tms.columns.wipShort', { count: form.wip_limit })}</span>}
                    </div>
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                    <Button type="button" variant="secondary" onClick={onClose}>{t('tms.columns.cancel')}</Button>
                    <Button type="submit" disabled={saving || !form.name.trim()}>{t('tms.columns.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
