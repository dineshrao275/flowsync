import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Avatar from '../../components/ui/Avatar';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Pagination from '../../components/ui/Pagination';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td, TableEmpty } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { employeeUrl } from '../../utils/deepLinks';
import EmployeeFormModal from './EmployeeFormModal';

/**
 * The employee directory.
 *
 * The department filter is an **exact** match on the backend: "everyone in
 * Engineering" and "everyone under Engineering" are different questions, and a
 * directory that answered the second when asked the first would print a smaller
 * number than the headcount badge on the org page. The subtree answer belongs to
 * the org page, which is holding the tree.
 */
const FILTER_DEFAULTS = {
    q: '',
    status: '',
    work_mode: '',
    employment_type_id: '',
    manager_id: '',
    department_id: '',
    joined_from: '',
    joined_to: '',
    sort: 'name',
    dir: 'asc',
};

const COLUMNS = [
    { key: 'name', label: 'Employee', sortable: true },
    { key: 'code', label: 'Code', sortable: true },
    { key: 'designation', label: 'Designation', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'manager', label: 'Manager', sortable: false },
    { key: 'joining_date', label: 'Joined', sortable: true },
];

export default function Employees() {
    usePageTitle('Employees');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const [filters, setFilters] = useState(FILTER_DEFAULTS);
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [creating, setCreating] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Employees' }]);
    }, [setCrumbs]);

    const load = useCallback(
        (targetPage, activeFilters) => {
            setError(null);

            const params = { page: targetPage, per_page: 20 };

            Object.entries(activeFilters).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) params[key] = value;
            });

            return api
                .get('/hrms/employees', { params })
                .then(({ data: response }) => setData(response))
                .catch((err) => {
                    if (err.response?.status === 403) {
                        navigate('/403', { replace: true });
                        return;
                    }

                    setError('Unable to load the employee directory.');
                });
        },
        [navigate],
    );

    // `page` is read for its side effect of re-running on change; the value it
    // carries is unused here because the page number travels inside `filters`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => {
        load(page, filters);
    }, [page, filters, load]);

    function setFilter(key, value) {
        // Any filter change invalidates the current page: staying on page 4 of a
        // 2-page result renders an empty table and looks like data loss.
        setPage(1);
        setFilters((f) => ({ ...f, [key]: value }));
    }

    function sortBy(key) {
        const column = COLUMNS.find((c) => c.key === key);

        if (!column?.sortable) return;

        setPage(1);
        setFilters((f) => ({
            ...f,
            sort: key,
            // Same column clicked twice flips the direction, which is the
            // behaviour everyone already expects from a table header.
            dir: f.sort === key && f.dir === 'asc' ? 'desc' : 'asc',
        }));
    }

    async function create(payload) {
        setSaving(true);

        try {
            const { data: response } = await api.post('/hrms/employees', payload);

            setCreating(false);
            toast.success(`${response.employee.display_name} added.`);
            // The write endpoint returns the record, not a message, but the list
            // has to come back from the server regardless: filters, counts and
            // the new code all come from the query, not from our own guess.
            await load(page, filters);
        } finally {
            setSaving(false);
        }
    }

    const rows = useMemo(() => data?.employees ?? null, [data]);
    const filterOptions = data?.filters ?? {};
    const canCreate = can('permission:hrms.employees.manage');

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Employees</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {data ? `${data.pagination.total} record${data.pagination.total === 1 ? '' : 's'}` : '—'}
                    </p>
                </div>

                {canCreate && (
                    <Button onClick={() => setCreating(true)}>New employee</Button>
                )}
            </div>

            <div className="grid gap-3 rounded-xl border border-gray-200/70 bg-white p-3 sm:grid-cols-2 lg:grid-cols-4">
                <Input
                    label="Search"
                    placeholder="Name or code"
                    value={filters.q}
                    onChange={(e) => setFilter('q', e.target.value)}
                />
                <Select
                    label="Status"
                    value={filters.status}
                    onChange={(e) => setFilter('status', e.target.value)}
                >
                    <option value="">Any status</option>
                    {(filterOptions.statuses ?? []).map((status) => (
                        <option key={status.value} value={status.value}>
                            {status.label}
                        </option>
                    ))}
                </Select>
                <Select label="Work mode" value={filters.work_mode} onChange={(e) => setFilter('work_mode', e.target.value)}>
                    <option value="">Any mode</option>
                    {(filterOptions.work_modes ?? []).map((mode) => (
                        <option key={mode.value} value={mode.value}>
                            {mode.label}
                        </option>
                    ))}
                </Select>
                <Select
                    label="Employment type"
                    value={filters.employment_type_id}
                    onChange={(e) => setFilter('employment_type_id', e.target.value)}
                >
                    <option value="">Any type</option>
                    {(filterOptions.employment_types ?? []).map((type) => (
                        <option key={type.id} value={type.id}>
                            {type.name}
                        </option>
                    ))}
                </Select>
                <Select
                    label="Manager"
                    value={filters.manager_id}
                    onChange={(e) => setFilter('manager_id', e.target.value)}
                >
                    <option value="">Anyone</option>
                    {(filterOptions.managers ?? []).map((manager) => (
                        <option key={manager.id} value={manager.id}>
                            {manager.name}
                        </option>
                    ))}
                </Select>
                <Select
                    label="Department"
                    value={filters.department_id}
                    onChange={(e) => setFilter('department_id', e.target.value)}
                >
                    <option value="">Any department</option>
                    {(filterOptions.departments ?? []).map((department) => (
                        <option key={department.id} value={department.id}>
                            {department.name}
                        </option>
                    ))}
                </Select>
                <Input
                    label="Joined from"
                    type="date"
                    value={filters.joined_from}
                    onChange={(e) => setFilter('joined_from', e.target.value)}
                />
                <Input
                    label="Joined to"
                    type="date"
                    value={filters.joined_to}
                    onChange={(e) => setFilter('joined_to', e.target.value)}
                />
            </div>

            {error && <Alert>{error}</Alert>}

            <Table>
                <thead>
                    <tr>
                        {COLUMNS.map((column) => (
                            <Th
                                key={column.key}
                                align={column.key === 'joining_date' ? 'right' : undefined}
                                aria-sort={column.sortable ? (filters.sort === column.key ? (filters.dir === 'asc' ? 'ascending' : 'descending') : 'none') : undefined}
                            >
                                {column.sortable ? (
                                    <button
                                        type="button"
                                        onClick={() => sortBy(column.key)}
                                        className="inline-flex items-center gap-1 font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-800"
                                    >
                                        {column.label}
                                        {filters.sort === column.key && (
                                            <span aria-hidden="true">{filters.dir === 'asc' ? '↑' : '↓'}</span>
                                        )}
                                    </button>
                                ) : (
                                    column.label
                                )}
                            </Th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows === null ? (
                        <tr>
                            <Td colSpan={COLUMNS.length} className="py-12 text-center">
                                <Spinner />
                            </Td>
                        </tr>
                    ) : rows.length === 0 ? (
                        <TableEmpty colSpan={COLUMNS.length}>
                            {filters.q || filters.status || filters.work_mode || filters.employment_type_id || filters.manager_id || filters.department_id
                                ? 'No employees match these filters.'
                                : 'No employees yet.'}
                        </TableEmpty>
                    ) : (
                        rows.map((employee, index) => (
                            <tr
                                key={employee.id}
                                className="animate-fade-in transition-colors duration-150 hover:bg-gray-50"
                                style={{ animationDelay: `${index * 25}ms` }}
                            >
                                <Td>
                                    <span className="flex items-center gap-2.5">
                                        <Avatar name={employee.display_name} size="sm" />
                                        <span className="min-w-0">
                                            <Link
                                                to={employeeUrl(employee.id)}
                                                className="block truncate font-medium text-gray-800 hover:text-indigo-700"
                                            >
                                                {employee.display_name}
                                            </Link>
                                            {employee.user?.email && (
                                                <span className="block truncate text-xs text-gray-400">
                                                    {employee.user.email}
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                </Td>
                                <Td className="font-mono text-xs text-gray-500">{employee.employee_code}</Td>
                                <Td className="text-gray-600">{employee.designation ?? '—'}</Td>
                                <Td>
                                    <span
                                        className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                        style={{ backgroundColor: `${employee.status_color}22`, color: employee.status_color }}
                                    >
                                        <span
                                            className="h-1.5 w-1.5 rounded-full"
                                            style={{ backgroundColor: employee.status_color }}
                                        />
                                        {employee.status_label}
                                    </span>
                                </Td>
                                <Td className="text-gray-600">
                                    {employee.manager ? (
                                        employee.manager.display_name
                                    ) : (
                                        <span className="text-gray-300">—</span>
                                    )}
                                </Td>
                                <Td align="right" className="whitespace-nowrap text-gray-600">
                                    {employee.joining_date ?? '—'}
                                </Td>
                            </tr>
                        ))
                    )}
                </tbody>
            </Table>

            {data && (
                <Pagination
                    page={data.pagination.current_page}
                    pages={data.pagination.last_page}
                    total={data.pagination.total}
                    onChange={setPage}
                />
            )}

            {creating && (
                <EmployeeFormModal
                    options={filterOptions}
                    saving={saving}
                    onClose={() => setCreating(false)}
                    onCreate={create}
                />
            )}
        </div>
    );
}
