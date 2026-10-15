import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Spinner from '../../components/ui/Spinner';
import { useToast } from '../../context/ToastContext';
import { employeeUrl } from '../../utils/deepLinks';
import { useConfirm } from '../../components/ui/ConfirmDialog';

/**
 * The people sitting in one department, and the control that moves them in or
 * out of it.
 *
 * Two gates, and the second one is the honest one. Reading the roster needs
 * `hrms.employees.view` because these are employee records, not org rows — a
 * reader holding `hrms.org.view` can see the shape of the company without being
 * handed a list of names. Writing needs `hrms.employees.manage`, because
 * assigning a person is a write to that person's record: the API enforces
 * exactly that, and requiring `hrms.org.manage` on top of it would invent a
 * second rule the server does not have.
 *
 * The list is the directory filtered by `department_id`, which is an **exact**
 * match by design — "everyone in Engineering", not "everyone under it". The
 * subtree count is already on the tree node, so a panel that quietly widened
 * its own filter would print a different total than the badge beside it.
 */
export default function DepartmentMembersPanel({ department, canManage, onChanged }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [term, setTerm] = useState('');
    const [candidates, setCandidates] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(
        (departmentId) => {
            setError(null);

            return api
                .get('/hrms/employees', { params: { department_id: departmentId, per_page: 50 } })
                .then(({ data: response }) => setData(response))
                .catch((err) => {
                    // Inline, not a /403: the org page itself is readable —
                    // only the members strip needs the directory permission.
                    if (err.response?.status === 403) {
                        setError(t('hrms.deptMembers.forbidden'));
                        return;
                    }

                    setError(t('hrms.deptMembers.loadFailed'));
                });
        },
        [t],
    );

    useEffect(() => {
        setData(null);
        setCandidates(null);
        setTerm('');

        if (department) load(department.id);
    }, [department, load]);

    // Candidates are searched, not listed: a tenant with thousands of employees
    // would need a dropdown of thousands of rows, and a report-line picker
    // cannot seed a department anyway (the same reason `managers` in
    // EmployeeFormModal is the whole directory rather than only existing
    // reports).
    useEffect(() => {
        if (term.trim().length < 2) {
            setCandidates(null);
            return;
        }

        let active = true;

        api
            .get('/hrms/employees', { params: { q: term.trim(), per_page: 8 } })
            .then(({ data: response }) => {
                if (active) setCandidates(response.employees ?? []);
            })
            .catch(() => {
                if (active) setCandidates([]);
            });

        return () => {
            active = false;
        };
    }, [term]);

    async function assign(employee) {
        setBusy(true);

        try {
            await api.put(`/hrms/employees/${employee.id}`, { department_id: department.id });
            toast.success(t('hrms.deptMembers.moved', { name: employee.display_name, department: department.name }));
            setTerm('');
            setCandidates(null);
            await load(department.id);
            onChanged();
        } catch (err) {
            toast.error(err?.response?.data?.message ?? t('hrms.deptMembers.moveFailed'));
        } finally {
            setBusy(false);
        }
    }

    async function remove(employee) {
        if (!(await confirm({
            title: t('hrms.deptMembers.removeTitle'),
            message: t('hrms.deptMembers.removeMessage', { name: employee.display_name, department: department.name }),
            variant: 'danger',
            confirmLabel: t('hrms.deptMembers.removeConfirm'),
        }))) return;

        setBusy(true);

        try {
            // `null`, not a missing key: the API treats an absent field as
            // "leave it alone", so omitting `department_id` would report a
            // successful removal while the record stayed where it was.
            await api.put(`/hrms/employees/${employee.id}`, { department_id: null });
            toast.success(t('hrms.deptMembers.removed', { name: employee.display_name, department: department.name }));
            await load(department.id);
            onChanged();
        } catch (err) {
            toast.error(err?.response?.data?.message ?? t('hrms.deptMembers.removeFailed'));
        } finally {
            setBusy(false);
        }
    }

    const members = data?.employees ?? null;
    const taken = new Set((members ?? []).map((e) => e.id));
    const suggestions = (candidates ?? []).filter((person) => !taken.has(person.id));

    return (
        <div className="space-y-3">
            {canManage && (
                <div className="relative">
                    <Input
                        label={t('hrms.deptMembers.addSomeone')}
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder={t('hrms.deptMembers.addPlaceholder')}
                        disabled={busy}
                    />

                    {suggestions.length > 0 && (
                        <ul className="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-popover">
                            {suggestions.map((person) => (
                                <li key={person.id}>
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={() => assign(person)}
                                        className="flex w-full items-baseline justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-gray-50 disabled:opacity-60"
                                    >
                                        <span className="truncate text-gray-800">{person.display_name}</span>
                                        <span className="shrink-0 font-mono text-xs text-gray-400">
                                            {person.employee_code}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {error && <Alert>{error}</Alert>}

            {members === null ? (
                <div className="py-8 text-center">
                    <Spinner />
                </div>
            ) : members.length === 0 ? (
                <EmptyState
                    title={t('hrms.deptMembers.emptyTitle')}
                    description={
                        canManage
                            ? t('hrms.deptMembers.emptyManage')
                            : t('hrms.deptMembers.emptyView')
                    }
                />
            ) : (
                <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200/70">
                    {members.map((person) => (
                        <li key={person.id} className="flex items-center gap-2 px-3 py-2">
                            <Link
                                to={employeeUrl(person.id)}
                                className="min-w-0 flex-1 truncate text-sm text-gray-800 hover:text-[var(--accent)]"
                            >
                                {person.display_name}
                            </Link>
                            <span className="shrink-0 text-xs text-gray-400">{person.designation ?? '—'}</span>
                            {canManage && (
                                <Button variant="ghost" size="sm" disabled={busy} onClick={() => remove(person)}>
                                    {t('hrms.deptMembers.remove')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
