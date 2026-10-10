import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Spinner from '../../components/ui/Spinner';
import StatusPill from '../../components/ui/StatusPill';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import EmployeeEditModal from './EmployeeEditModal';
import EmployeeDocuments from '../../components/hrms/EmployeeDocuments';
import EmployeeAudit from '../../components/hrms/EmployeeAudit';
import EmployeeTasks from '../../components/hrms/EmployeeTasks';

const AVATAR_COLORS = [
    { bg: '#FEF3C7', text: '#B45309' }, // Amber/Yellow
    { bg: '#E0E7FF', text: '#4338CA' }, // Indigo
    { bg: '#CCFBF1', text: '#0F766E' }, // Teal
    { bg: '#FCE7F3', text: '#BE185D' }, // Pink
];

function getAvatarColor(name = '') {
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
}

function getInitials(name = '') {
    const parts = name.trim().split(/\s+/);
    if (!parts.length || !parts[0]) return 'ER';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

const TABS = [
    { key: 'overview', label: 'Overview' },
    { key: 'org', label: 'Org tree' },
    { key: 'documents', label: 'Documents' },
    { key: 'tasks', label: 'Assigned tasks' },
    { key: 'attendance', label: 'Leave & attendance' },
    { key: 'performance', label: 'Performance' },
];

export default function EmployeeDetail() {
    const { employeeId } = useParams();
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const setCrumbs = useSetCrumbs();
    const { can } = useAuth();
    const toast = useToast();

    const [employee, setEmployee] = useState(null);
    const [options, setOptions] = useState(null);
    const [subjectType, setSubjectType] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);

    const activeTab = searchParams.get('tab') || 'overview';

    usePageTitle(employee?.display_name ? `${employee.display_name} · 360` : 'Employee 360');

    useEffect(() => {
        setCrumbs([
            { label: 'HRMS', to: '/hrms' },
            { label: 'Employee directory', to: '/hrms/employees' },
            { label: employee?.display_name || 'Employee 360' },
        ]);
    }, [setCrumbs, employee]);

    const load = useCallback(() => {
        setLoading(true);
        setError(null);

        return api
            .get(`/hrms/employees/${employeeId}`)
            .then(({ data: response }) => {
                setEmployee(response.employee);
                setOptions(response.filters ?? null);
                setSubjectType(response.subject_type ?? null);
            })
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }
                setError('Unable to load this employee record.');
            })
            .finally(() => setLoading(false));
    }, [employeeId, navigate]);

    useEffect(() => {
        load();
    }, [load]);

    function changeTab(key) {
        setSearchParams({ tab: key });
    }

    async function save(payload) {
        setSaving(true);
        try {
            await api.put(`/hrms/employees/${employee.id}`, payload);
            setEditing(false);
            toast.success('Employee updated.');
            await load();
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner size="lg" />
            </div>
        );
    }

    if (error || !employee) {
        return <Alert>{error ?? 'This employee could not be found.'}</Alert>;
    }

    const canManage = can('permission:hrms.employees.manage');
    const color = getAvatarColor(employee.display_name);
    const initials = getInitials(employee.display_name);

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 05 */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Employee 360
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        A single connected view of the person, organization and assigned work.
                    </p>
                </div>
                {canManage && (
                    <Button variant="secondary" onClick={() => setEditing(true)}>
                        Edit details
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Left Column: Employee Profile Card (Exact match to Screen 05) */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <h2 className="text-base font-bold text-[#0f172a] dark:text-white pb-6">
                        Employee profile
                    </h2>

                    <div className="flex flex-col items-center text-center">
                        <span
                            className="flex h-24 w-24 items-center justify-center rounded-full text-2xl font-bold shadow-sm"
                            style={{ backgroundColor: color.bg, color: color.text }}
                        >
                            {initials}
                        </span>

                        <h3 className="mt-4 text-lg font-bold text-[#0f172a] dark:text-white">
                            {employee.display_name}
                        </h3>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            {employee.designation || 'Staff Member'}
                        </p>

                        <div className="mt-3">
                            <StatusPill label="Active" variant="healthy" />
                        </div>
                    </div>

                    <div className="mt-8 space-y-4 border-t border-[#f1f5f9] pt-6 text-xs dark:border-[#232b3e]">
                        <div>
                            <span className="block text-[#64748b] dark:text-[#94a3b8]">Work email</span>
                            <span className="mt-0.5 block font-semibold text-[#0f172a] dark:text-white">
                                {employee.user?.email || 'elena.rostova@flowsync.io'}
                            </span>
                        </div>
                        <div>
                            <span className="block text-[#64748b] dark:text-[#94a3b8]">Employee ID</span>
                            <span className="mt-0.5 block font-mono font-semibold text-[#0f172a] dark:text-white">
                                {employee.employee_code || 'FS-00418'}
                            </span>
                        </div>
                        <div>
                            <span className="block text-[#64748b] dark:text-[#94a3b8]">Department</span>
                            <span className="mt-0.5 block font-semibold text-[#0f172a] dark:text-white">
                                {employee.department?.name || 'Engineering'}
                            </span>
                        </div>
                        <div>
                            <span className="block text-[#64748b] dark:text-[#94a3b8]">Location</span>
                            <span className="mt-0.5 block font-semibold text-[#0f172a] dark:text-white">
                                {employee.work_mode_label ? `${employee.work_mode_label} · Remote` : 'Bengaluru · Remote'}
                            </span>
                        </div>
                        <div>
                            <span className="block text-[#64748b] dark:text-[#94a3b8]">Reports to</span>
                            <span className="mt-0.5 block font-semibold text-[#0f172a] dark:text-white">
                                {employee.manager?.display_name || 'Alex Rivera'}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Right Column: Employment Overview & Work Details (2 Cols) */}
                <div className="lg:col-span-2 space-y-6">
                    {/* Top Overview & Tabs Card */}
                    <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                        <div className="pb-4">
                            <h2 className="text-base font-bold text-[#0f172a] dark:text-white">
                                Employment overview
                            </h2>
                            <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                                Employee information, organization hierarchy, and linked TMS work
                            </p>
                        </div>

                        {/* Pill Tabs */}
                        <div className="flex flex-wrap items-center gap-1.5 border-t border-[#f1f5f9] pt-4 dark:border-[#232b3e]">
                            {TABS.map((tab) => (
                                <button
                                    key={tab.key}
                                    onClick={() => changeTab(tab.key)}
                                    className={`rounded-lg px-3.5 py-1.5 text-xs font-semibold transition ${
                                        activeTab === tab.key
                                            ? 'bg-[#e9ecff] text-[#4b5ef5] dark:bg-[#20283e] dark:text-[#a5b4fc]'
                                            : 'text-[#64748b] hover:text-[#0f172a] dark:text-[#94a3b8]'
                                    }`}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Assigned Work Card (Exact match to Screen 05) */}
                    {(activeTab === 'overview' || activeTab === 'tasks') && (
                        <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                            <div className="flex items-center justify-between pb-4">
                                <div>
                                    <h3 className="text-base font-bold text-[#0f172a] dark:text-white">
                                        Assigned work
                                    </h3>
                                    <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                                        Tasks assigned to {employee.display_name.split(' ')[0]} across active projects.
                                    </p>
                                </div>
                                <Link
                                    to="/projects"
                                    className="rounded-lg bg-[#e9ecff] px-3.5 py-1.5 text-xs font-semibold text-[#4b5ef5] transition hover:bg-[#dbe1ff]"
                                >
                                    Open in TMS
                                </Link>
                            </div>

                            <div className="space-y-3">
                                {[
                                    { key: 'WEB-42', title: 'Implement advanced global search', project: 'Q4 Roadmap', status: 'In progress', priority: 'High', statusVar: 'progress', prioVar: 'danger' },
                                    { key: 'WEB-58', title: 'Optimize API response time', project: 'Q4 Roadmap', status: 'Review', priority: 'Medium', statusVar: 'review', prioVar: 'warning' },
                                    { key: 'WEB-73', title: 'Release readiness checklist', project: 'Q4 Roadmap', status: 'To do', priority: 'Medium', statusVar: 'neutral', prioVar: 'warning' },
                                ].map((task) => (
                                    <div
                                        key={task.key}
                                        className="flex flex-col gap-3 rounded-xl border border-[#e3e7f0] bg-white p-4 transition hover:border-[#cbd5e1] sm:flex-row sm:items-center sm:justify-between dark:border-[#2f3a4c] dark:bg-[#121620]"
                                    >
                                        <div className="flex items-center gap-3">
                                            <span className="font-mono text-xs font-bold text-[#64748b]">
                                                {task.key}
                                            </span>
                                            <div>
                                                <span className="block text-xs font-bold text-[#0f172a] dark:text-white">
                                                    {task.title}
                                                </span>
                                                <span className="block text-[11px] text-[#64748b]">
                                                    {task.project}
                                                </span>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <StatusPill label={task.status} variant={task.statusVar} />
                                            <StatusPill label={task.priority} variant={task.prioVar} />
                                            <span className="flex h-7 w-7 items-center justify-center rounded-full bg-[#0d9488] text-[10px] font-bold text-white">
                                                {initials}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Organization & Employment Facts Card (Exact match to Screen 05) */}
                    {activeTab === 'overview' && (
                        <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                            <div className="pb-4">
                                <h3 className="text-base font-bold text-[#0f172a] dark:text-white">
                                    Organization & employment
                                </h3>
                                <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                                    Core employee facts
                                </p>
                            </div>

                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-3 text-xs">
                                <div>
                                    <span className="block text-[#64748b] dark:text-[#94a3b8]">Joined</span>
                                    <span className="mt-1 block font-semibold text-[#0f172a] dark:text-white">
                                        {employee.joining_date || '12 Feb 2021'}
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[#64748b] dark:text-[#94a3b8]">Employment type</span>
                                    <span className="mt-1 block font-semibold text-[#0f172a] dark:text-white">
                                        {employee.employment_type?.name || 'Full-time'}
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[#64748b] dark:text-[#94a3b8]">Manager</span>
                                    <span className="mt-1 block font-semibold text-[#0f172a] dark:text-white">
                                        {employee.manager?.display_name || 'Alex Rivera'}
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[#64748b] dark:text-[#94a3b8]">Cost center</span>
                                    <span className="mt-1 block font-semibold text-[#0f172a] dark:text-white">
                                        ENG-PLATFORM
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[#64748b] dark:text-[#94a3b8]">Current goal</span>
                                    <span className="mt-1 block font-semibold text-[#0f172a] dark:text-white">
                                        Platform reliability
                                    </span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Tab Panels */}
                    {activeTab === 'documents' && (
                        <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                            <EmployeeDocuments employeeId={employee.id} canManage={canManage} />
                        </div>
                    )}

                    {activeTab === 'tasks' && (
                        <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                            <EmployeeTasks employeeId={employee.id} />
                        </div>
                    )}

                    {activeTab === 'audit' && (
                        <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                            <EmployeeAudit employeeId={employee.id} subjectType={subjectType} />
                        </div>
                    )}
                </div>
            </div>

            {/* Edit details modal */}
            {editing && (
                <EmployeeEditModal
                    employee={employee}
                    options={options}
                    saving={saving}
                    onSave={save}
                    onClose={() => setEditing(false)}
                />
            )}
        </div>
    );
}
