import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import Modal from '../components/ui/Modal';
import Input from '../components/ui/Input';
import Switch from '../components/ui/Switch';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import { Table, Th, Td } from '../components/ui/Table';
import { fieldClass } from '../components/ui/fieldStyles';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import usePageTitle from '../hooks/usePageTitle';

const ENTITIES = ['employee', 'task', 'project', 'asset', 'expense'];
const BLANK = { label: '', type: 'text', options: '', required: false, is_filterable: true, is_sensitive: false, help_text: '' };

/** Options are typed one per line; each line becomes { value: slug, label }. */
export function parseOptions(text) {
    return text.split('\n').map((line) => line.trim()).filter(Boolean).map((label) => ({ label, value: label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || label }));
}

export default function CustomFields() {
    const { t } = useTranslation();
    usePageTitle(t('admin.customFields.pageTitle'));
    const toast = useToast();
    const confirm = useConfirm();
    const [entity, setEntity] = useState('employee');
    const [fields, setFields] = useState(null);
    const [types, setTypes] = useState([]);
    const [editing, setEditing] = useState(null); // null | 'new' | field
    const [form, setForm] = useState(BLANK);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => {
        setFields(null);
        api.get('/custom-fields', { params: { entity, include_inactive: 1 } })
            .then(({ data }) => { setFields(data.fields); setTypes(data.types); })
            .catch(() => setFields([]));
    }, [entity]);

    useEffect(load, [load]);

    function open(field) {
        setErrors({});
        if (field === 'new') {
            setForm(BLANK);
        } else {
            setForm({ ...BLANK, ...field, options: (field.options || []).map((o) => o.label).join('\n'), help_text: field.help_text || '' });
        }
        setEditing(field);
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        const selectish = ['select', 'multiselect'].includes(form.type);
        const body = {
            label: form.label.trim(), required: form.required, is_filterable: form.is_filterable, is_sensitive: form.is_sensitive,
            help_text: form.help_text || null, ...(selectish ? { options: parseOptions(form.options) } : {}),
        };
        try {
            if (editing === 'new') await api.post('/custom-fields', { ...body, entity, type: form.type });
            else await api.put(`/custom-fields/${editing.id}`, { ...body, is_active: editing.is_active });
            toast.success(t('admin.customFields.saved'));
            setEditing(null);
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    async function toggleActive(field) {
        await api.put(`/custom-fields/${field.id}`, { is_active: !field.is_active }).catch(() => toast.error(t('admin.customFields.failed')));
        load();
    }

    async function remove(field) {
        if (!(await confirm({ title: t('admin.customFields.deleteTitle'), message: t('admin.customFields.deleteMessage', { name: field.label }), variant: 'danger' }))) return;
        await api.delete(`/custom-fields/${field.id}`).then(() => toast.success(t('admin.customFields.deleted'))).catch(() => toast.error(t('admin.customFields.failed')));
        load();
    }

    const selectish = ['select', 'multiselect'].includes(form.type);

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('admin.customFields.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('admin.customFields.subtitle')}</p>
                </div>
                <Button onClick={() => open('new')}>{t('admin.customFields.add')}</Button>
            </div>

            <div role="tablist" className="flex flex-wrap gap-1 border-b border-gray-200">
                {ENTITIES.map((e) => (
                    <button key={e} type="button" role="tab" aria-selected={entity === e} onClick={() => setEntity(e)}
                        className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium ${entity === e ? 'border-[var(--accent)] text-[var(--accent)]' : 'border-transparent text-gray-500 hover:text-gray-800'}`}>
                        {t(`admin.customFields.entity.${e}`)}
                    </button>
                ))}
            </div>

            <Card>
                {!fields ? <ContentSkeleton size="md" /> : fields.length === 0 ? (
                    <p className="py-8 text-center text-sm text-gray-500">{t('admin.customFields.empty', { entity: t(`admin.customFields.entity.${entity}`).toLowerCase() })}</p>
                ) : (
                    <Table>
                        <thead><tr><Th>{t('admin.customFields.label')}</Th><Th>{t('admin.customFields.type')}</Th><Th>{t('admin.customFields.flags')}</Th><Th><span className="sr-only">{t('admin.customFields.actions')}</span></Th></tr></thead>
                        <tbody>
                            {fields.map((f) => (
                                <tr key={f.id} className={f.is_active ? '' : 'opacity-60'}>
                                    <Td><p className="font-medium text-gray-900">{f.label}</p><p className="font-mono text-xs text-gray-400">{f.key}</p></Td>
                                    <Td>{t(`admin.customFields.types.${f.type}`)}</Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            {f.required && <Badge tone="warning">{t('admin.customFields.required')}</Badge>}
                                            {f.is_sensitive && <Badge tone="danger">{t('admin.customFields.sensitive')}</Badge>}
                                            {f.is_filterable && <Badge tone="neutral">{t('admin.customFields.filterable')}</Badge>}
                                            {!f.is_active && <Badge tone="neutral">{t('admin.customFields.archived')}</Badge>}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className="flex gap-2">
                                            <Button size="sm" variant="secondary" onClick={() => open(f)}>{t('admin.customFields.edit')}</Button>
                                            <Button size="sm" variant="ghost" onClick={() => toggleActive(f)}>{f.is_active ? t('admin.customFields.archive') : t('admin.customFields.restore')}</Button>
                                            <Button size="sm" variant="ghost" onClick={() => remove(f)}>{t('admin.customFields.delete')}</Button>
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Modal open={editing !== null} onClose={() => setEditing(null)} size="sm" title={editing === 'new' ? t('admin.customFields.addTitle') : t('admin.customFields.editTitle')} subtitle={t('admin.customFields.entity.' + entity)}>
                <form onSubmit={save}>
                    <div className="space-y-4 px-6 py-5">
                        <Input label={t('admin.customFields.label')} value={form.label} onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))} error={errors.label} required maxLength={120} />
                        <div>
                            <label htmlFor="cf-type" className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.customFields.type')}</label>
                            <select id="cf-type" className={fieldClass} value={form.type} disabled={editing !== 'new'} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}>
                                {types.map((ty) => <option key={ty} value={ty}>{t(`admin.customFields.types.${ty}`)}</option>)}
                            </select>
                            {editing !== 'new' && <p className="mt-1 text-xs text-gray-500">{t('admin.customFields.typeLocked')}</p>}
                        </div>
                        {selectish && (
                            <div>
                                <label htmlFor="cf-options" className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.customFields.options')}</label>
                                <textarea id="cf-options" rows={4} className={fieldClass} value={form.options} onChange={(e) => setForm((f) => ({ ...f, options: e.target.value }))} />
                                <p className={`mt-1 text-xs ${errors.options ? 'text-red-600' : 'text-gray-500'}`}>{errors.options || t('admin.customFields.optionsHint')}</p>
                            </div>
                        )}
                        <Input label={t('admin.customFields.helpText')} value={form.help_text} onChange={(e) => setForm((f) => ({ ...f, help_text: e.target.value }))} maxLength={255} />
                        <Switch label={t('admin.customFields.required')} checked={form.required} onChange={(v) => setForm((f) => ({ ...f, required: v }))} />
                        <Switch label={t('admin.customFields.filterable')} description={t('admin.customFields.filterableHint')} checked={form.is_filterable} onChange={(v) => setForm((f) => ({ ...f, is_filterable: v }))} />
                        <Switch label={t('admin.customFields.sensitive')} description={t('admin.customFields.sensitiveHint')} checked={form.is_sensitive} onChange={(v) => setForm((f) => ({ ...f, is_sensitive: v }))} />
                        {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setEditing(null)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" loading={saving} disabled={!form.label.trim()}>{t('tms.columns.save')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
