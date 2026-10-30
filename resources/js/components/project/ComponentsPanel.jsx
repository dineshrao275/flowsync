import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useResource } from '../../hooks/useResource';
import { useToast } from '../../context/ToastContext';
import Avatar from '../ui/Avatar';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Card from '../ui/Card';
import EmptyState from '../ui/EmptyState';
import FormField from '../ui/FormField';
import Input from '../ui/Input';
import Resource from '../ui/Resource';
import TableSkeleton from '../ui/TableSkeleton';
import Textarea from '../ui/Textarea';
import PersonSelect from '../ui/PersonSelect';
import Alert from '../ui/Alert';
import { useConfirm } from '../ui/ConfirmDialog';

const blank = { name: '', description: '', lead_user_id: '' };

/** Project components: the tags a task can carry ("Backend API", "UI"). */
export default function ComponentsPanel({ projectId, members = [], canManage }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(blank);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const res = useResource(
        (signal) => api.get(`/projects/${projectId}/components`, { signal }).then(({ data }) => data.components ?? []),
        [projectId],
    );

    function reset() {
        setEditing(null);
        setForm(blank);
        setErrors({});
    }

    function startEdit(component) {
        setEditing(component);
        setForm({
            name: component.name ?? '',
            description: component.description ?? '',
            lead_user_id: component.lead_user_id ? String(component.lead_user_id) : '',
        });
        setErrors({});
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        const body = {
            name: form.name,
            description: form.description || null,
            lead_user_id: form.lead_user_id ? Number(form.lead_user_id) : null,
        };
        try {
            if (editing) {
                await api.put(`/projects/${projectId}/components/${editing.id}`, body);
            } else {
                await api.post(`/projects/${projectId}/components`, body);
            }
            toast.success(t(editing ? 'tms.components.updated' : 'tms.components.created'));
            reset();
            res.reload();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    async function remove(component) {
        const ok = await confirm({
            title: t('tms.components.deleteTitle'),
            message: t('tms.components.deleteMessage', { name: component.name, count: component.tasks_count ?? 0 }),
            variant: 'danger',
        });
        if (!ok) return;

        try {
            await api.delete(`/projects/${projectId}/components/${component.id}`);
            toast.success(t('tms.components.deleted'));
            if (editing?.id === component.id) reset();
            res.reload();
        } catch (err) {
            toast.error(fieldErrors(err).form || t('tms.components.deleteFailed'));
        }
    }

    return (
        <Card title={t('tms.components.title')} subtitle={t('tms.components.subtitle')}>
            <Resource {...res} skeleton={<TableSkeleton columns={3} />} errorTitle={t('tms.components.loadFailed')}>
                {(components) => (
                    <>
                        {components.length === 0 ? (
                            <EmptyState title={t('tms.components.empty')} description={t('tms.components.emptyHint')} />
                        ) : (
                            <ul className="divide-y divide-line">
                                {components.map((component) => (
                                    <li key={component.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-medium text-fg">{component.name}</span>
                                                <Badge>{t('tms.components.tasksCount', { count: component.tasks_count ?? 0 })}</Badge>
                                            </div>
                                            {component.description && (
                                                <p className="mt-1 max-w-2xl text-sm text-fg-muted">{component.description}</p>
                                            )}
                                            <div className="mt-1 flex items-center gap-2 text-caption text-fg-muted">
                                                {component.lead ? (
                                                    <>
                                                        <Avatar name={component.lead.name} photoUrl={component.lead.photo_url} size="xs" />
                                                        <span>{component.lead.name}</span>
                                                    </>
                                                ) : (
                                                    <span>{t('tms.components.noLead')}</span>
                                                )}
                                            </div>
                                        </div>
                                        {canManage && (
                                            <div className="flex items-center gap-2">
                                                <Button size="sm" variant="ghost" onClick={() => startEdit(component)}>
                                                    {t('tms.components.edit')}
                                                </Button>
                                                <Button size="sm" variant="danger" onClick={() => remove(component)}>
                                                    {t('tms.components.delete')}
                                                </Button>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {canManage ? (
                            <form onSubmit={save} className="mt-4 space-y-3 border-t border-line pt-4">
                                {editing && <p className="text-sm font-medium text-fg">{t('tms.components.editTitle', { name: editing.name })}</p>}
                                {errors.form && <Alert>{errors.form}</Alert>}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Input
                                        label={t('tms.components.name')}
                                        name="component-name"
                                        value={form.name}
                                        onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                                        error={errors.name}
                                        placeholder={t('tms.components.namePlaceholder')}
                                        required
                                    />
                                    <PersonSelect
                                        label={t('tms.components.lead')}
                                        people={members}
                                        value={form.lead_user_id}
                                        onChange={(e) => setForm((f) => ({ ...f, lead_user_id: e.target.value }))}
                                        placeholder={t('tms.components.noLead')}
                                        error={errors.lead_user_id}
                                    />
                                </div>
                                <FormField label={t('tms.components.description')} error={errors.description}>
                                    <Textarea
                                        rows={2}
                                        value={form.description}
                                        onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                                        placeholder={t('tms.components.descriptionPlaceholder')}
                                    />
                                </FormField>
                                <div className="flex gap-2">
                                    <Button type="submit" loading={saving}>
                                        {editing ? t('tms.components.save') : t('tms.components.add')}
                                    </Button>
                                    {editing && (
                                        <Button type="button" variant="secondary" onClick={reset}>
                                            {t('tms.components.cancel')}
                                        </Button>
                                    )}
                                </div>
                            </form>
                        ) : (
                            <p className="mt-4 border-t border-line pt-4 text-sm text-fg-muted">{t('tms.components.archivedHint')}</p>
                        )}
                    </>
                )}
            </Resource>
        </Card>
    );
}
