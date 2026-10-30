import { useCallback, useEffect, useMemo, useState } from 'react';
import ListSavedFilters from '../../components/ui/ListSavedFilters';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Avatar from '../../components/ui/Avatar';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Pagination from '../../components/ui/Pagination';
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { Table, Th, Td, TableEmpty } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { employeeUrl } from '../../utils/deepLinks';
import EmployeeFormModal from './EmployeeFormModal';
import EmployeeBulkModal from '../../components/hrms/EmployeeBulkModal';

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
    { key: 'name', labelKey: 'hrms.employees.colEmployee', sortable: true },
    { key: 'code', labelKey: 'hrms.employees.colCode', sortable: true },
    { key: 'designation', labelKey: 'hrms.employees.colDesignation', sortable: true },
    { key: 'status', labelKey: 'hrms.employees.colStatus', sortable: true },
    { key: 'manager', labelKey: 'hrms.employees.colManager', sortable: false },
    { key: 'joining_date', labelKey: 'hrms.employees.colJoined', sortable: true },
];

export default function Employees() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.employees.pageTitle'));
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
    const [bulk, setBulk] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.employees.pageTitle') }]);
    }, [setCrumbs, t]);

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

                    setError(t('hrms.employees.loadFailed'));
                });
        },
        [navigate, t],
    );

    // `page` is read for its side effect of re-running on change; the value it
    // carries is unused here because the page number travels inside `filters`.
     
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
            toast.success(t('hrms.employees.added', { name: response.employee.display_name }));
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
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.employees.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {data ? (data.pagination.total === 1 ? t('hrms.employees.recordsOne') : t('hrms.employees.records', { count: data.pagination.total })) : '—'}
                    </p>
                </div>

                {canCreate && (
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" onClick={() => setBulk('import')}>{t('hrms.employees.importCsv')}</Button>
                        <Button variant="secondary" onClick={() => setBulk('status')}>{t('hrms.employees.bulkStatus')}</Button>
                        <Button onClick={() => setCreating(true)}>{t('hrms.employees.new')}</Button>
                    </div>
                )}
            </div>

            <div className="grid gap-3 rounded-xl border border-gray-200/70 bg-white p-3 sm:grid-cols-2 lg:grid-cols-4">
                <Input
                    label={t('hrms.employees.search')}
                    placeholder={t('hrms.employees.searchPlaceholder')}
                    value={filters.q}
                    onChange={(e) => setFilter('q', e.target.value)}
                />
                <Select
                    label={t('hrms.employees.status')}
                    value={filters.status}
                    onChange={(e) => setFilter('status', e.target.value)}
                >
                    <option value="">{t('hrms.employees.anyStatus')}</option>
                    {(filterOptions.statuses ?? []).map((status) => (
                        <option key={status.value} value={status.value}>
                            {status.label}
                        </option>
                    ))}
                </Select>
                <Select label={t('hrms.employees.workMode')} value={filters.work_mode} onChange={(e) => setFilter('work_mode', e.target.value)}>
                    <option value="">{t('hrms.employees.anyMode')}</option>
                    {(filterOptions.work_modes ?? []).map((mode) => (
                        <option key={mode.value} value={mode.value}>
                            {mode.label}
                        </option>
                    ))}
                </Select>
                <Select
                    label={t('hrms.employees.employmentType')}
                    value={filters.employment_type_id}
                    onChange={(e) => setFilter('employment_type_id', e.target.value)}
                >
                    <option value="">{t('hrms.employees.anyType')}</option>
                    {(filterOptions.employment_types ?? []).map((type) => (
                        <option key={type.id} value={type.id}>
                            {type.name}
                        </option>
                    ))}
                </Select>
                <Select
                    label={t('hrms.employees.manager')}
                    value={filters.manager_id}
                    onChange={(e) => setFilter('manager_id', e.target.value)}
                >
                    <option value="">{t('hrms.employees.anyone')}</option>
                    {(filterOptions.managers ?? []).map((manager) => (
                        <option key={manager.id} value={manager.id}>
                            {manager.name}
                        </option>
                    ))}
                </Select>
                <Select
                    label={t('hrms.employees.department')}
                    value={filters.department_id}
                    onChange={(e) => setFilter('department_id', e.target.value)}
                >
                    <option value="">{t('hrms.employees.anyDepartment')}</option>
                    {(filterOptions.departments ?? []).map((department) => (
                        <option key={department.id} value={department.id}>
                            {department.name}
                        </option>
                    ))}
                </Select>
                <Input
                    label={t('hrms.employees.joinedFrom')}
                    type="date"
                    value={filters.joined_from}
                    onChange={(e) => setFilter('joined_from', e.target.value)}
                />
                <Input
                    label={t('hrms.employees.joinedTo')}
                    type="date"
                    value={filters.joined_to}
                    onChange={(e) => setFilter('joined_to', e.target.value)}
                />
                <div className="flex items-end">
                    <ListSavedFilters
                        page="hrms-employees"
                        value={filters}
                        onApply={(saved) => {
                            setPage(1);
                            setFilters({ ...FILTER_DEFAULTS, ...saved });
                        }}
                    />
                </div>
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
                                        {t(column.labelKey)}
                                        {filters.sort === column.key && (
                                            <span aria-hidden="true">{filters.dir === 'asc' ? '↑' : '↓'}</span>
                                        )}
                                    </button>
                                ) : (
                                    t(column.labelKey)
                                )}
                            </Th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows === null ? (
                        <tr>
                            <Td colSpan={COLUMNS.length} className="py-12 text-center">
                                <ContentSkeleton size="sm" />
                            </Td>
                        </tr>
                    ) : rows.length === 0 ? (
                        <TableEmpty colSpan={COLUMNS.length}>
                            {filters.q || filters.status || filters.work_mode || filters.employment_type_id || filters.manager_id || filters.department_id
                                ? t('hrms.employees.emptyFiltered')
                                : t('hrms.employees.empty')}
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
                                                className="block truncate font-medium text-gray-800 hover:text-[var(--accent)]"
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

            {bulk && (
                <EmployeeBulkModal
                    mode={bulk}
                    onClose={() => setBulk(null)}
                    onDone={() => {
                        setBulk(null);
                        load(page, filters);
                    }}
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
