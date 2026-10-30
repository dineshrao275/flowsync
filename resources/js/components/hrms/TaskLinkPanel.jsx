import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import Button from '../ui/Button';
import Select from '../ui/Select';
import Input from '../ui/Input';
import ContentSkeleton from '../ui/ContentSkeleton';
import EmptyState from '../ui/EmptyState';
import { useConfirm } from '../ui/ConfirmDialog';

const KINDS = [
    { value: 'goal', key: 'goal' },
    { value: 'onboarding', key: 'onboarding' },
    { value: 'attendance', key: 'attendance' },
    { value: 'expense', key: 'expense' },
    { value: 'payroll', key: 'payroll' },
    { value: 'leave', key: 'leave' },
    { value: 'review', key: 'review' },
];

/**
 * The HR bridge on a project task: which employee records count this
 * work, and for what kind of claim. Reads ride the task policy (a 403
 * renders the gated note, not a spinner forever); writes need the edit
 * ability the drawer already computed. The employee picker lists
 * directory rows, so it only renders for callers who may read them.
 */
export default function TaskLinkPanel({ task, canManage }) {
    const { t } = useTranslation();
    const { can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const [links, setLinks] = useState(null);
    const [forbidden, setForbidden] = useState(false);
    const [employees, setEmployees] = useState([]);
    const [form, setForm] = useState({ employee_id: '', kind: 'goal', note: '' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const canReadDirectory = can('hrms.employees.view');

    const load = useCallback(() => {
        api.get(`/hrms/tasks/${task.id}/links`)
            .then(({ data }) => setLinks(data.task_links ?? []))
            .catch((err) => {
                if (err.response?.status === 403) setForbidden(true);
            });
    }, [task.id]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!canReadDirectory) return;

        api.get('/hrms/employees', { params: { per_page: 250 } })
            .then(({ data }) => setEmployees(data.employees ?? []))
            .catch(() => setEmployees([]));
    }, [canReadDirectory]);

    async function submit(e) {
        e.preventDefault();
        setErrors({});
        setSaving(true);

        try {
            await api.post(`/hrms/tasks/${task.id}/links`, {
                employee_id: Number(form.employee_id),
                kind: form.kind,
                ...(form.note ? { note: form.note } : {}),
            });
            toast.success(t('hrms.links.linked'));
            setForm({ employee_id: '', kind: 'goal', note: '' });
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    async function unlink(link) {
        if (!(await confirm({
            title: t('hrms.links.removeTitle'),
            message: t('hrms.links.removeMessage', { name: link.employee?.name || t('hrms.links.removeFallback') }),
            variant: 'danger',
            confirmLabel: t('hrms.links.removeConfirm'),
        }))) return;

        try {
            await api.delete(`/hrms/tasks/${task.id}/links/${link.id}`);
            toast.success(t('hrms.links.removed'));
            load();
        } catch {
            toast.error(t('hrms.links.removeFailed'));
        }
    }

    if (forbidden) {
        return <p className="py-8 text-center text-sm text-gray-400">{t('hrms.links.forbidden')}</p>;
    }

    if (!links) return <ContentSkeleton size="sm" />;

    return (
        <div className="space-y-4">
            {links.length === 0 ? (
                <EmptyState title={t('hrms.links.emptyTitle')} message={t('hrms.links.emptyHint')} />
            ) : (
                <ul className="divide-y divide-gray-100">
                    {links.map((link) => (
                        <li key={link.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                            <Link
                                to={`/hrms/employees/${link.employee?.id}`}
                                className="text-sm font-medium text-[var(--accent-soft-text)] hover:text-[var(--accent-soft-text)] hover:underline"
                            >
                                {link.employee?.name ?? t('hrms.links.someone')}
                            </Link>
                            <span className="rounded-full bg-[var(--accent-soft)] px-2 py-0.5 text-xs text-[var(--accent-soft-text)]">
                                {link.kind_label ?? link.kind}
                            </span>
                            {link.note && <span className="text-xs italic text-gray-500">“{link.note}”</span>}
                            {canManage && (
                                <Button size="sm" variant="ghost" onClick={() => unlink(link)} className="ml-auto">
                                    {t('hrms.links.unlink')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && canReadDirectory && (
                <form onSubmit={submit} className="space-y-3 rounded-xl border border-gray-200/70 p-4">
                    <Select
                        label={t('hrms.links.employee')}
                        value={form.employee_id}
                        onChange={(e) => setForm((prev) => ({ ...prev, employee_id: e.target.value }))}
                        error={errors.employee_id}
                        required
                    >
                        <option value="">{t('hrms.links.select')}</option>
                        {employees.map((employee) => (
                            <option key={employee.id} value={employee.id}>
                                {employee.display_name ?? employee.name}
                            </option>
                        ))}
                    </Select>
                    <Select
                        label={t('hrms.links.kind')}
                        value={form.kind}
                        onChange={(e) => setForm((prev) => ({ ...prev, kind: e.target.value }))}
                        error={errors.kind}
                    >
                        {KINDS.map((kind) => (
                            <option key={kind.value} value={kind.value}>
                                {t(`hrms.links.kinds.${kind.key}`)}
                            </option>
                        ))}
                    </Select>
                    <Input
                        label={t('hrms.links.note')}
                        value={form.note}
                        onChange={(e) => setForm((prev) => ({ ...prev, note: e.target.value }))}
                        error={errors.note}
                        maxLength={2000}
                    />
                    {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}
                    <Button type="submit" loading={saving} disabled={!form.employee_id}>
                        {t('hrms.links.submit')}
                    </Button>
                </form>
            )}
        </div>
    );
}
