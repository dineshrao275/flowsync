import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import Button from '../ui/Button';
import Select from '../ui/Select';
import Input from '../ui/Input';
import Spinner from '../ui/Spinner';
import EmptyState from '../ui/EmptyState';

const KINDS = [
    { value: 'goal', label: 'Goal evidence' },
    { value: 'onboarding', label: 'Onboarding' },
    { value: 'attendance', label: 'Attendance' },
    { value: 'expense', label: 'Expense' },
    { value: 'payroll', label: 'Payroll' },
    { value: 'leave', label: 'Leave' },
    { value: 'review', label: 'Review' },
];

/**
 * The HR bridge on a project task: which employee records count this
 * work, and for what kind of claim. Reads ride the task policy (a 403
 * renders the gated note, not a spinner forever); writes need the edit
 * ability the drawer already computed. The employee picker lists
 * directory rows, so it only renders for callers who may read them.
 */
export default function TaskLinkPanel({ task, canManage }) {
    const { can } = useAuth();
    const toast = useToast();
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

        api.get('/hrms/employees', { params: { per_page: 100 } })
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
            toast.success('Task linked.');
            setForm({ employee_id: '', kind: 'goal', note: '' });
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    async function unlink(link) {
        try {
            await api.delete(`/hrms/tasks/${task.id}/links/${link.id}`);
            toast.success('Link removed.');
            load();
        } catch {
            toast.error('Unable to remove this link.');
        }
    }

    if (forbidden) {
        return <p className="py-8 text-center text-sm text-gray-400">You cannot view HR links on this task.</p>;
    }

    if (!links) return <Spinner />;

    return (
        <div className="space-y-4">
            {links.length === 0 ? (
                <EmptyState title="No HR links" message="No employee record counts this task yet." />
            ) : (
                <ul className="divide-y divide-gray-100">
                    {links.map((link) => (
                        <li key={link.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                            <Link
                                to={`/hrms/employees/${link.employee?.id}`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                            >
                                {link.employee?.name ?? 'Someone'}
                            </Link>
                            <span className="rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700">
                                {link.kind_label ?? link.kind}
                            </span>
                            {link.note && <span className="text-xs italic text-gray-500">“{link.note}”</span>}
                            {canManage && (
                                <Button size="sm" variant="ghost" onClick={() => unlink(link)} className="ml-auto">
                                    Unlink
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && canReadDirectory && (
                <form onSubmit={submit} className="space-y-3 rounded-xl border border-gray-200/70 p-4">
                    <Select
                        label="Employee"
                        value={form.employee_id}
                        onChange={(e) => setForm((prev) => ({ ...prev, employee_id: e.target.value }))}
                        error={errors.employee_id}
                        required
                    >
                        <option value="">Select…</option>
                        {employees.map((employee) => (
                            <option key={employee.id} value={employee.id}>
                                {employee.display_name ?? employee.name}
                            </option>
                        ))}
                    </Select>
                    <Select
                        label="Kind"
                        value={form.kind}
                        onChange={(e) => setForm((prev) => ({ ...prev, kind: e.target.value }))}
                        error={errors.kind}
                    >
                        {KINDS.map((kind) => (
                            <option key={kind.value} value={kind.value}>
                                {kind.label}
                            </option>
                        ))}
                    </Select>
                    <Input
                        label="Note (optional)"
                        value={form.note}
                        onChange={(e) => setForm((prev) => ({ ...prev, note: e.target.value }))}
                        error={errors.note}
                        maxLength={2000}
                    />
                    {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}
                    <Button type="submit" loading={saving} disabled={!form.employee_id}>
                        Link employee
                    </Button>
                </form>
            )}
        </div>
    );
}
