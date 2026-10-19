import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BookmarkSimple } from '@phosphor-icons/react';
import api, { fieldErrors } from '../../services/api';
import Menu from '../ui/Menu';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import Modal from '../ui/Modal';
import Input from '../ui/Input';
import Switch from '../ui/Switch';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../ui/ConfirmDialog';

/**
 * Saved task filters for one project: apply one from the menu, save the current filter set
 * (private, or shared with the project when the caller manages it), delete your own.
 */
export default function SavedFilters({ projectId, filters, onApply, canShare }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const [saved, setSaved] = useState([]);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({ name: '', shared: false });
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get(`/projects/${projectId}/saved-filters`)
            .then(({ data }) => setSaved(data.filters))
            .catch(() => setSaved([]));
    }, [projectId]);

    useEffect(load, [load]);

    const hasFilters = Object.values(filters).some((v) => v !== '' && v != null);

    async function save(e) {
        e.preventDefault();
        setError(null);
        try {
            await api.post(`/projects/${projectId}/saved-filters`, { name: form.name.trim(), filters, is_shared: canShare && form.shared });
            toast.success(t('tms.savedFilters.saved'));
            setSaving(false);
            setForm({ name: '', shared: false });
            load();
        } catch (err) {
            setError(fieldErrors(err).name || fieldErrors(err).form || t('tms.savedFilters.saveFailed'));
        }
    }

    async function remove(filter) {
        if (!(await confirm({ title: t('tms.savedFilters.deleteTitle'), message: t('tms.savedFilters.deleteMessage', { name: filter.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`/projects/${projectId}/saved-filters/${filter.id}`);
            load();
        } catch {
            toast.error(t('tms.savedFilters.deleteFailed'));
        }
    }

    const items = [
        ...saved.map((f) => ({
            key: `apply-${f.id}`,
            label: f.is_shared ? `${f.name} · ${t('tms.savedFilters.shared')}` : f.name,
            onSelect: () => onApply({ ...f.filters }),
        })),
        ...(saved.length ? [{ separator: true }] : []),
        { key: 'save', label: t('tms.savedFilters.saveCurrent'), disabled: !hasFilters, onSelect: () => setSaving(true) },
        ...saved.filter((f) => f.mine || canShare).map((f) => ({
            key: `del-${f.id}`, label: t('tms.savedFilters.delete', { name: f.name }), danger: true, onSelect: () => remove(f),
        })),
    ];

    return (
        <>
            <Menu
                label={t('tms.savedFilters.menu')}
                align="left"
                items={items}
                trigger={(props) => (
                    <Button {...props} size="sm" variant="secondary">
                        <Icon as={BookmarkSimple} size="sm" className="mr-1.5" />
                        {t('tms.savedFilters.title')}
                        {saved.length > 0 && <span className="ml-1.5 text-xs text-gray-500">{saved.length}</span>}
                    </Button>
                )}
            />
            <Modal open={saving} onClose={() => setSaving(false)} size="sm" title={t('tms.savedFilters.saveCurrent')}>
                <form onSubmit={save}>
                    <div className="space-y-4 px-6 py-5">
                        <Input label={t('tms.savedFilters.name')} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={error} required maxLength={80} />
                        {canShare && (
                            <Switch label={t('tms.savedFilters.shareLabel')} description={t('tms.savedFilters.shareHint')} checked={form.shared} onChange={(shared) => setForm((f) => ({ ...f, shared }))} />
                        )}
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setSaving(false)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" disabled={!form.name.trim()}>{t('tms.columns.save')}</Button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
