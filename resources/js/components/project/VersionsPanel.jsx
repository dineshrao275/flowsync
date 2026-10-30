import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useResource } from '../../hooks/useResource';
import { useToast } from '../../context/ToastContext';
import { formatDateOnly } from '../../utils/format';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Card from '../ui/Card';
import Checkbox from '../ui/Checkbox';
import EmptyState from '../ui/EmptyState';
import FormField from '../ui/FormField';
import Input from '../ui/Input';
import Resource from '../ui/Resource';
import TableSkeleton from '../ui/TableSkeleton';
import Textarea from '../ui/Textarea';
import Alert from '../ui/Alert';
import { useConfirm } from '../ui/ConfirmDialog';

const blank = { name: '', description: '', release_date: '', released: false, archived: false };

/** Project versions/releases: the "v2.0.0" a task ships in. */
export default function VersionsPanel({ projectId, canManage }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(blank);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const res = useResource(
        (signal) => api.get(`/projects/${projectId}/versions`, { signal }).then(({ data }) => data.versions ?? []),
        [projectId],
    );

    function reset() {
        setEditing(null);
        setForm(blank);
        setErrors({});
    }

    function startEdit(version) {
        setEditing(version);
        setForm({
            name: version.name ?? '',
            description: version.description ?? '',
            release_date: version.release_date ? String(version.release_date).slice(0, 10) : '',
            released: Boolean(version.released),
            archived: Boolean(version.archived),
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
            release_date: form.release_date || null,
            released: form.released,
            archived: form.archived,
        };
        try {
            if (editing) {
                await api.put(`/projects/${projectId}/versions/${editing.id}`, body);
            } else {
                await api.post(`/projects/${projectId}/versions`, body);
            }
            toast.success(t(editing ? 'tms.versions.updated' : 'tms.versions.created'));
            reset();
            res.reload();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    async function remove(version) {
        const ok = await confirm({
            title: t('tms.versions.deleteTitle'),
            message: t('tms.versions.deleteMessage', { name: version.name, count: version.tasks_count ?? 0 }),
            variant: 'danger',
        });
        if (!ok) return;

        try {
            await api.delete(`/projects/${projectId}/versions/${version.id}`);
            toast.success(t('tms.versions.deleted'));
            if (editing?.id === version.id) reset();
            res.reload();
        } catch (err) {
            toast.error(fieldErrors(err).form || t('tms.versions.deleteFailed'));
        }
    }

    return (
        <Card title={t('tms.versions.title')} subtitle={t('tms.versions.subtitle')}>
            <Resource {...res} skeleton={<TableSkeleton columns={4} />} errorTitle={t('tms.versions.loadFailed')}>
                {(versions) => (
                    <>
                        {versions.length === 0 ? (
                            <EmptyState title={t('tms.versions.empty')} description={t('tms.versions.emptyHint')} />
                        ) : (
                            <ul className="divide-y divide-line">
                                {versions.map((version) => (
                                    <li key={version.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-medium text-fg">{version.name}</span>
                                                {version.released && <Badge tone="success">{t('tms.versions.badges.released')}</Badge>}
                                                {version.archived && <Badge tone="neutral">{t('tms.versions.badges.archived')}</Badge>}
                                                <Badge>{t('tms.versions.tasksCount', { count: version.tasks_count ?? 0 })}</Badge>
                                            </div>
                                            {version.description && (
                                                <p className="mt-1 max-w-2xl text-sm text-fg-muted">{version.description}</p>
                                            )}
                                            <p className="mt-1 text-caption text-fg-muted">
                                                {version.release_date
                                                    ? t('tms.versions.releasesOn', { date: formatDateOnly(version.release_date) })
                                                    : t('tms.versions.noReleaseDate')}
                                            </p>
                                        </div>
                                        {canManage && (
                                            <div className="flex items-center gap-2">
                                                <Button size="sm" variant="ghost" onClick={() => startEdit(version)}>
                                                    {t('tms.versions.edit')}
                                                </Button>
                                                <Button size="sm" variant="danger" onClick={() => remove(version)}>
                                                    {t('tms.versions.delete')}
                                                </Button>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {canManage ? (
                            <form onSubmit={save} className="mt-4 space-y-3 border-t border-line pt-4">
                                {editing && <p className="text-sm font-medium text-fg">{t('tms.versions.editTitle', { name: editing.name })}</p>}
                                {errors.form && <Alert>{errors.form}</Alert>}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Input
                                        label={t('tms.versions.name')}
                                        name="version-name"
                                        value={form.name}
                                        onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                                        error={errors.name}
                                        placeholder={t('tms.versions.namePlaceholder')}
                                        required
                                    />
                                    <Input
                                        label={t('tms.versions.releaseDate')}
                                        name="version-release-date"
                                        type="date"
                                        value={form.release_date}
                                        onChange={(e) => setForm((f) => ({ ...f, release_date: e.target.value }))}
                                        error={errors.release_date}
                                    />
                                </div>
                                <FormField label={t('tms.versions.description')} error={errors.description}>
                                    <Textarea
                                        rows={2}
                                        value={form.description}
                                        onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                                        placeholder={t('tms.versions.descriptionPlaceholder')}
                                    />
                                </FormField>
                                <div className="flex flex-wrap gap-5">
                                    <Checkbox
                                        label={t('tms.versions.released')}
                                        checked={form.released}
                                        onChange={(e) => setForm((f) => ({ ...f, released: e.target.checked }))}
                                    />
                                    <Checkbox
                                        label={t('tms.versions.archived')}
                                        checked={form.archived}
                                        onChange={(e) => setForm((f) => ({ ...f, archived: e.target.checked }))}
                                    />
                                </div>
                                <div className="flex gap-2">
                                    <Button type="submit" loading={saving}>
                                        {editing ? t('tms.versions.save') : t('tms.versions.add')}
                                    </Button>
                                    {editing && (
                                        <Button type="button" variant="secondary" onClick={reset}>
                                            {t('tms.versions.cancel')}
                                        </Button>
                                    )}
                                </div>
                            </form>
                        ) : (
                            <p className="mt-4 border-t border-line pt-4 text-sm text-fg-muted">{t('tms.versions.archivedHint')}</p>
                        )}
                    </>
                )}
            </Resource>
        </Card>
    );
}
