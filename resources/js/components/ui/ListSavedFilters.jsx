import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BookmarkSimple } from '@phosphor-icons/react';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import Menu from './Menu';
import Button from './Button';
import Icon from './Icon';
import Modal from './Modal';
import Input from './Input';
import Switch from './Switch';
import { useConfirm } from './ConfirmDialog';

/** Drop empty values so "no filter" and "cleared filter" compare (and save) the same. */
function compact(filters) {
    return Object.fromEntries(Object.entries(filters || {}).filter(([, v]) => v !== '' && v != null && !(Array.isArray(v) && v.length === 0)));
}

/**
 * Saved filters for a listing page.
 *
 *   <ListSavedFilters page="users" value={{ q, role }} onApply={(f) => { setQ(f.q ?? ''); setRole(f.role ?? ''); }} />
 *
 * `page` is the list's slug. `value` is the page's CURRENT filter object (flat; strings/numbers/arrays) and `onApply`
 * receives a saved one — keys the filter doesn't hold are simply absent, so the page should reset them to its defaults.
 * Personal filters need no permission; sharing with everyone needs `tenant.manage` (tenant) / any platform account (admin).
 * `scope="system"` talks to the super-admin store.
 */
export default function ListSavedFilters({ page, value, onApply, scope = 'tenant', className = '' }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const { can } = useAuth();
    const base = scope === 'system' ? '/system/saved-filters' : '/saved-filters';
    const canShare = scope === 'system' || can('tenant.manage');
    const [saved, setSaved] = useState([]);
    const [modal, setModal] = useState(false);
    const [form, setForm] = useState({ name: '', shared: false });
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get(base, { params: { page } }).then(({ data }) => setSaved(Array.isArray(data?.filters) ? data.filters : [])).catch(() => setSaved([]));
    }, [base, page]);

    useEffect(load, [load]);

    const current = compact(value);
    const hasFilters = Object.keys(current).length > 0;
    const active = saved.find((f) => JSON.stringify(compact(f.filters)) === JSON.stringify(current));

    async function save(e) {
        e.preventDefault();
        setError(null);
        try {
            await api.post(base, { page, name: form.name.trim(), filters: current, is_shared: canShare && form.shared });
            toast.success(t('savedFilters.saved'));
            setModal(false);
            setForm({ name: '', shared: false });
            load();
        } catch (err) {
            const errors = fieldErrors(err);
            setError(errors.name || errors.form || errors.filters || err.response?.data?.message || t('savedFilters.saveFailed'));
        }
    }

    async function overwrite(filter) {
        try {
            await api.put(`${base}/${filter.id}`, { filters: current });
            toast.success(t('savedFilters.updated'));
            load();
        } catch {
            toast.error(t('savedFilters.saveFailed'));
        }
    }

    async function remove(filter) {
        if (!(await confirm({ title: t('savedFilters.deleteTitle'), message: t('savedFilters.deleteMessage', { name: filter.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`${base}/${filter.id}`);
            load();
        } catch {
            toast.error(t('savedFilters.deleteFailed'));
        }
    }

    const manageable = saved.filter((f) => f.can_manage);
    const items = [
        ...saved.map((f) => ({
            key: `apply-${f.id}`,
            label: `${active?.id === f.id ? '✓ ' : ''}${f.name}${f.is_shared ? ` · ${t('savedFilters.shared')}` : ''}`,
            onSelect: () => onApply({ ...f.filters }),
        })),
        ...(saved.length ? [{ separator: true }] : []),
        { key: 'save', label: t('savedFilters.saveCurrent'), disabled: !hasFilters, onSelect: () => setModal(true) },
        ...manageable.map((f) => ({ key: `upd-${f.id}`, label: t('savedFilters.update', { name: f.name }), disabled: !hasFilters, onSelect: () => overwrite(f) })),
        ...manageable.map((f) => ({ key: `del-${f.id}`, label: t('savedFilters.delete', { name: f.name }), danger: true, onSelect: () => remove(f) })),
    ];

    return (
        <>
            <Menu
                label={t('savedFilters.menu')}
                align="left"
                items={items}
                trigger={(props) => (
                    <Button {...props} size="sm" variant="secondary" className={className}>
                        <Icon as={BookmarkSimple} size="sm" className="mr-1.5" />
                        {active ? active.name : t('savedFilters.title')}
                        {saved.length > 0 && <span className="ml-1.5 text-xs text-gray-500">{saved.length}</span>}
                    </Button>
                )}
            />
            <Modal open={modal} onClose={() => setModal(false)} size="sm" title={t('savedFilters.saveCurrent')}>
                <form onSubmit={save}>
                    <div className="space-y-4 px-6 py-5">
                        <Input label={t('savedFilters.name')} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={error} required maxLength={80} />
                        {canShare && (
                            <Switch label={t('savedFilters.shareLabel')} description={t('savedFilters.shareHint')} checked={form.shared} onChange={(shared) => setForm((f) => ({ ...f, shared }))} />
                        )}
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setModal(false)}>{t('savedFilters.cancel')}</Button>
                        <Button type="submit" disabled={!form.name.trim()}>{t('savedFilters.save')}</Button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
