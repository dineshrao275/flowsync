import { useCallback, useEffect, useMemo, useState } from 'react';
import PersonSelect from '../../components/ui/PersonSelect';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import usePageTitle from '../../hooks/usePageTitle';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Alert from '../../components/ui/Alert';
import { fieldClass } from '../../components/ui/fieldStyles';

const BLANK = { id: null, name: '', document_type_slug: 'experience_letter', body: '' };

/** Letter templates with {{merge}} fields; generating one files a PDF in the employee's documents. */
export default function Letters() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.letters.pageTitle'));
    const toast = useToast();
    const [data, setData] = useState(null);
    const [people, setPeople] = useState([]);
    const [editing, setEditing] = useState(null);
    const [errors, setErrors] = useState({});
    const [gen, setGen] = useState({ template_id: '', employee_id: '', fields: {} });
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/letter-templates').then(({ data: res }) => setData(res)).catch(() => setError(t('hrms.letters.loadFailed')));
    }, [t]);

    useEffect(() => {
        load();
        api.get('/hrms/employees', { params: { per_page: 100 } }).then(({ data: res }) => setPeople(res.employees || [])).catch(() => {});
    }, [load]);

    const template = data?.templates.find((tpl) => String(tpl.id) === String(gen.template_id));
    const extraFields = useMemo(() => {
        if (!template || !data) return [];
        return [...new Set([...template.body.matchAll(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi)].map((m) => m[1].toLowerCase()))].filter((f) => !data.fields.includes(f));
    }, [template, data]);

    async function save(e) {
        e.preventDefault();
        setErrors({});
        try {
            const body = { name: editing.name, document_type_slug: editing.document_type_slug, body: editing.body };
            if (editing.id) await api.put(`/hrms/letter-templates/${editing.id}`, body);
            else await api.post('/hrms/letter-templates', body);
            toast.success(t('hrms.letters.saved'));
            setEditing(null);
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function addStarters() {
        await api.post('/hrms/letter-templates/starters');
        load();
    }

    async function generate(e) {
        e.preventDefault();
        setError(null);
        try {
            await api.post(`/hrms/employees/${gen.employee_id}/letters`, { template_id: Number(gen.template_id), fields: gen.fields });
            toast.success(t('hrms.letters.generated'));
            setGen({ template_id: '', employee_id: '', fields: {} });
        } catch (err) {
            const fe = fieldErrors(err);
            setError(fe.fields || fe.template || fe.template_id || t('hrms.letters.generateFailed'));
        }
    }

    if (!data) return (error ? <Alert>{error}</Alert> : <ContentSkeleton size="lg" />);

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('hrms.letters.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('hrms.letters.subtitle')}</p>
                </div>
                <div className="flex gap-2">
                    <Button variant="secondary" onClick={addStarters}>{t('hrms.letters.addStarters')}</Button>
                    <Button onClick={() => setEditing({ ...BLANK })}>{t('hrms.letters.newTemplate')}</Button>
                </div>
            </div>
            {error && <Alert>{error}</Alert>}

            <Card title={t('hrms.letters.generate')}>
                <form onSubmit={generate} className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label htmlFor="let-tpl" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.letters.template')}</label>
                        <select id="let-tpl" className={fieldClass} value={gen.template_id} onChange={(e) => setGen({ ...gen, template_id: e.target.value, fields: {} })}>
                            <option value="">—</option>
                            {data.templates.filter((x) => x.is_active).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="let-emp" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.letters.employee')}</label>
                        <PersonSelect id="let-emp" value={gen.employee_id} onChange={(e) => setGen({ ...gen, employee_id: e.target.value })} placeholder="—" people={people} />
                    </div>
                    {extraFields.map((f) => (
                        <Input key={f} label={f.replace(/_/g, ' ')} value={gen.fields[f] || ''} onChange={(e) => setGen({ ...gen, fields: { ...gen.fields, [f]: e.target.value } })} required />
                    ))}
                    <div className="flex items-end"><Button type="submit" disabled={!gen.template_id || !gen.employee_id}>{t('hrms.letters.generateBtn')}</Button></div>
                </form>
            </Card>

            <Card title={t('hrms.letters.templates')}>
                {data.templates.length === 0 ? <p className="py-6 text-center text-sm text-gray-500">{t('hrms.letters.empty')}</p> : (
                    <ul className="divide-y divide-gray-100">
                        {data.templates.map((x) => (
                            <li key={x.id} className="flex items-center justify-between gap-3 py-2">
                                <span className="text-sm font-medium text-gray-900">{x.name}</span>
                                <span className="flex gap-2">
                                    <Button size="sm" variant="secondary" onClick={() => setEditing(x)}>{t('hrms.common.edit')}</Button>
                                    <Button size="sm" variant="danger" onClick={async () => { await api.delete(`/hrms/letter-templates/${x.id}`); load(); }}>{t('hrms.common.delete')}</Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal open={!!editing} onClose={() => setEditing(null)} title={editing?.id ? t('hrms.letters.editTemplate') : t('hrms.letters.newTemplate')} subtitle={t('hrms.letters.fieldsHint', { fields: data.fields.map((f) => `{{${f}}}`).join(' ') })}>
                {editing && (
                    <form onSubmit={save}>
                        <div className="space-y-4 px-6 py-5">
                            <Input label={t('hrms.letters.name')} value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} error={errors.name} required />
                            <Input label={t('hrms.letters.documentType')} value={editing.document_type_slug} onChange={(e) => setEditing({ ...editing, document_type_slug: e.target.value })} error={errors.document_type_slug} required />
                            <div>
                                <label htmlFor="let-body" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.letters.body')}</label>
                                <textarea id="let-body" rows={10} className={fieldClass} value={editing.body} onChange={(e) => setEditing({ ...editing, body: e.target.value })} />
                                {errors.body && <p className="mt-1 text-xs text-red-600">{errors.body}</p>}
                            </div>
                        </div>
                        <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                            <Button type="button" variant="secondary" onClick={() => setEditing(null)}>{t('tms.columns.cancel')}</Button>
                            <Button type="submit">{t('hrms.letters.save')}</Button>
                        </div>
                    </form>
                )}
            </Modal>
        </div>
    );
}
