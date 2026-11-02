import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Input from '../ui/Input';
import { useToast } from '../../context/ToastContext';

/**
 * Create an asset category from the register dialog. Categories are tenant-built (no starters are
 * seeded), so without this a new tenant has a required Category field with nothing to choose.
 */
export default function AssetCategoryQuickAdd({ onCreated, empty = false }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [name, setName] = useState('');
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    async function add() {
        if (!name.trim()) return;
        setSaving(true);
        setError(null);
        try {
            const { data } = await api.post('/hrms/assets/categories', { name: name.trim() });
            toast.success(t('hrms.assets.categoryAdded'));
            setName('');
            onCreated(data.category);
        } catch (err) {
            setError(fieldErrors(err).name || fieldErrors(err).form || t('hrms.assets.loadFailed'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="sm:col-span-2 space-y-2">
            {empty && <p className="text-sm text-gray-600">{t('hrms.assets.noCategories')}</p>}
            <div className="flex items-end gap-2">
                <div className="flex-1">
                    <Input label={t('hrms.assets.newCategory')} value={name} error={error} onChange={(e) => setName(e.target.value)} />
                </div>
                <Button type="button" variant="secondary" loading={saving} disabled={!name.trim()} onClick={add}>{t('hrms.assets.addCategory')}</Button>
            </div>
        </div>
    );
}
