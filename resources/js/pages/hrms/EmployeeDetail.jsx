import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Avatar from '../../components/ui/Avatar';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { hrmsProfileTabs } from '../../utils/hrmsModules';
import { hrmsUrl } from '../../utils/deepLinks';
import EmployeeEditModal from './EmployeeEditModal';
import { CustomFieldValues } from '../../components/customFields/CustomFieldInputs';
import StatusHistory from './StatusHistory';
import EmployeeDocuments from '../../components/hrms/EmployeeDocuments';
import EmployeeAudit from '../../components/hrms/EmployeeAudit';
import EmployeeTasks from '../../components/hrms/EmployeeTasks';

/**
 * One employee's profile.
 *
 * Read-only apart from two deliberate exceptions — the profile fields and the
 * reporting line — because those are the two things an HR admin has to be able
 * to correct and nothing else on this page is a correction. Everything else
 * (status, exit) belongs to the offboarding phase that owns those rules, and a
 * generic editor here would be a second, weaker path to the same decisions.
 */
export default function EmployeeDetail() {
    const { t } = useTranslation();
    const { employeeId } = useParams();
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const setCrumbs = useSetCrumbs();
    const { user, can } = useAuth();
    const toast = useToast();

    const [employee, setEmployee] = useState(null);
    // The ledger and the pickers arrive as siblings of `employee` rather than
    // inside it: the status history is not a property of the person, and the
    // manager list is a query result, not a field anyone stores.
    const [history, setHistory] = useState([]);
    const [options, setOptions] = useState(null);
    // The trail key arrives as a sibling for the same reason: it names the
    // ledger row, not the person, and the Audit tab is the only reader.
    const [subjectType, setSubjectType] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [managerId, setManagerId] = useState('');
    const [savingManager, setSavingManager] = useState(false);

    // The audit slot is module-gated like every profile tab, but the rows
    // are permission-gated — the catalog cannot express that, so the tab is
    // appended here only for callers holding the ledger permission.
    const tabs = useMemo(() => {
        const base = hrmsProfileTabs(user?.modules ?? []).filter((tab) => tab.key !== 'audit');
        if (can('hrms.audit.view')) base.push({ key: 'audit', label: 'Audit' });
        return base.map((tab) => ({ ...tab, label: t(`hrms.employee.tabs.${tab.key}`, { defaultValue: tab.label }) }));
    }, [user?.modules, can, t]);
    const requested = searchParams.get('tab');
    const activeTab = tabs.some((tab) => tab.key === requested) ? requested : (tabs[0]?.key ?? 'overview');

    const load = useCallback(() => {
        setLoading(true);
        setError(null);

        return api
            .get(`/hrms/employees/${employeeId}`)
            .then(({ data: response }) => {
                setEmployee(response.employee);
                setHistory(response.status_history ?? []);
                setOptions(response.filters ?? null);
                setSubjectType(response.subject_type ?? null);
            })
            // A 403 is a permission answer and deserves the dedicated page; a
            // 404 is a record that is not in *this* tenant's database, and the
            // generic error state is the honest one for it.
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.employee.loadFailed'));
            })
            .finally(() => setLoading(false));
    }, [employeeId, navigate, t]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        setManagerId(employee?.manager?.id ? String(employee.manager.id) : '');
    }, [employee]);

    useEffect(() => {
        if (!employee) return;

        setCrumbs([
            { label: t('hrms.overview.pageTitle'), to: '/hrms' },
            { label: t('hrms.employees.pageTitle'), to: hrmsUrl('employees') },
            { label: employee.display_name },
        ]);
    }, [employee, setCrumbs, t]);

    usePageTitle(employee?.display_name ?? t('hrms.employee.fallbackTitle'));

    function changeTab(key) {
        // The tab is written back into the URL rather than held in state, so a
        // refresh and a shared link both land on the same section.
        if (key === activeTab) return;

        setSearchParams(key === tabs[0]?.key ? {} : { tab: key });
    }

    async function save(payload) {
        setSaving(true);

        try {
            await api.put(`/hrms/employees/${employee.id}`, payload);

            setEditing(false);
            toast.success(t('hrms.employee.saved'));
            // Refetched rather than patched: the response masks the personal
            // fields for a reader without the sensitive permission, so merging
            // our own draft back in would show a manager values the server is
            // not returning to them.
            await load();
        } finally {
            setSaving(false);
        }
    }

    async function saveManager() {
        setSavingManager(true);

        try {
            await api.post(`/hrms/employees/${employee.id}/manager`, {
                manager_id: managerId === '' ? null : Number(managerId),
            });

            toast.success(t('hrms.employee.reportingSaved'));
            await load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? t('hrms.employee.reportingFailed'));
        } finally {
            setSavingManager(false);
        }
    }

    if (loading) {
        return (
            <div className="py-16 text-center">
                <Spinner />
            </div>
        );
    }

    if (error || !employee) {
        return <Alert>{error ?? t('hrms.employee.notFound')}</Alert>;
    }

    const canManage = can('permission:hrms.employees.manage');

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-center gap-3">
                    <EmployeePhoto employee={employee} />
                    <div>
                        <h2 className="text-xl font-semibold text-gray-900">{employee.display_name}</h2>
                        <p className="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                            <span className="font-mono text-xs">{employee.employee_code}</span>
                            {employee.designation && <span>· {employee.designation}</span>}
                            <span
                                className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                style={{ backgroundColor: `${employee.status_color}22`, color: employee.status_color }}
                            >
                                <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: employee.status_color }} />
                                {employee.status_label}
                            </span>
                            {employee.tenure_years !== null && (
                                <span className="text-xs text-gray-400">
                                    {t('hrms.employee.tenure', { years: employee.tenure_years })}
                                </span>
                            )}
                        </p>
                    </div>
                </div>

                {canManage && (
                    <Button variant="secondary" onClick={() => setEditing(true)}>
                        {t('hrms.employee.editDetails')}
                    </Button>
                )}
            </div>

            {tabs.length > 1 && (
                <nav className="flex gap-1 border-b border-gray-200">
                    {tabs.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => changeTab(tab.key)}
                            className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                                activeTab === tab.key
                                    ? 'border-[var(--accent)] text-[var(--accent-soft-text)]'
                                    : 'border-transparent text-gray-500 hover:text-gray-800'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>
            )}

            {activeTab === 'overview' && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        <Panel title={t('hrms.employee.employment')}>
                            <Row label={t('hrms.employee.employmentType')} value={employee.employment_type?.name} />
                            <Row label={t('hrms.employee.workMode')} value={employee.work_mode_label} />
                            <Row label={t('hrms.employee.joined')} value={employee.joining_date} />
                            <Row label={t('hrms.employee.probationEnds')} value={employee.probation_end_date} />
                            <Row label={t('hrms.employee.confirmed')} value={employee.confirmation_date} />
                            {employee.exit_date && (
                                <Row label={t('hrms.employee.leftOn')} value={`${employee.exit_date}${employee.exited_reason ? ` · ${employee.exited_reason}` : ''}`} />
                            )}
                        </Panel>

                        <PersonalPanel employee={employee} t={t} />

                        {employee.custom_fields && Object.keys(employee.custom_fields).length > 0 && (
                            <Panel title={t('admin.customFields.sectionTitle')}>
                                <CustomFieldValues entity="employee" values={employee.custom_fields} Row={Row} />
                            </Panel>
                        )}

                        {canManage && (
                            <Panel title={t('hrms.employee.reporting')}>
                                <div className="flex flex-wrap items-end gap-3">
                                    <Select
                                        label={t('hrms.employee.manager')}
                                        className="min-w-56 flex-1"
                                        value={managerId}
                                        onChange={(e) => setManagerId(e.target.value)}
                                    >
                                        <option value="">{t('hrms.employee.noManager')}</option>
                                        {(options?.managers ?? [])
                                            .filter((manager) => manager.id !== employee.id)
                                            .map((manager) => (
                                                <option key={manager.id} value={manager.id}>
                                                    {manager.name}
                                                </option>
                                            ))}
                                    </Select>
                                    <Button
                                        onClick={saveManager}
                                        disabled={savingManager || managerId === (employee.manager ? String(employee.manager.id) : '')}
                                    >
                                        {savingManager ? t('hrms.employee.saving') : t('hrms.employee.save')}
                                    </Button>
                                </div>
                            </Panel>
                        )}
                    </div>

                    <StatusHistory history={history} />
                </div>
            )}

            {activeTab === 'documents' && <EmployeeDocuments employee={employee} />}

            {activeTab === 'audit' && <EmployeeAudit employeeId={employeeId} subjectType={subjectType} />}

            {activeTab === 'tasks' && <EmployeeTasks employeeId={employeeId} />}

            <div className="pt-2 text-sm">
                <Link to={hrmsUrl('employees')} className="text-[var(--accent-soft-text)] hover:text-[var(--accent)]">
                    {t('hrms.employee.back')}
                </Link>
            </div>

            {editing && (
                <EmployeeEditModal
                    employee={employee}
                    options={options}
                    saving={saving}
                    onClose={() => setEditing(false)}
                    onSave={save}
                />
            )}
        </div>
    );
}

/**
 * The photo when the caller may see it, initials otherwise.
 *
 * The initials fallback is the reason a null `photo_url` is not a bug: a face is
 * a personal field like any other, and the server hands out a signed link only
 * to a reader with the sensitive permission.
 */
function EmployeePhoto({ employee }) {
    if (employee.photo_url) {
        return (
            <img
                src={employee.photo_url}
                alt=""
                className="h-14 w-14 rounded-full object-cover"
                onError={(e) => {
                    // A signed link that has expired renders as a broken image,
                    // which is worse than the initials it replaced.
                    e.currentTarget.style.display = 'none';
                }}
            />
        );
    }

    return <Avatar name={employee.display_name} size="lg" />;
}

function PersonalPanel({ employee, t }) {
    if (employee.restricted) {
        return (
            <Panel title={t('hrms.employee.personal')}>
                <p className="text-sm text-gray-500">
                    {t('hrms.employee.restricted')}
                </p>
            </Panel>
        );
    }

    return (
        <Panel title={t('hrms.employee.personal')}>
            <Row label={t('hrms.employee.personalEmail')} value={employee.personal_email} />
            <Row label={t('hrms.employee.phone')} value={employee.phone} />
            <Row label={t('hrms.employee.dob')} value={employee.date_of_birth} />
            <Row label={t('hrms.employee.gender')} value={employee.gender} />
            <Row label={t('hrms.employee.marital')} value={employee.marital_status} />
            <Row label={t('hrms.employee.nationality')} value={employee.nationality} />
            <Row
                label={t('hrms.employee.address')}
                value={address(employee)}
            />
            <Row
                label={t('hrms.employee.emergency')}
                value={
                    employee.emergency_contact?.name
                        ? `${employee.emergency_contact.name}${
                              employee.emergency_contact.relation ? ` (${employee.emergency_contact.relation})` : ''
                          } · ${employee.emergency_contact.phone ?? t('hrms.employee.noNumber')}`
                        : null
                }
            />
            {employee.notes && <Row label={t('hrms.employee.notes')} value={employee.notes} />}
        </Panel>
    );
}

function address(employee) {
    return [employee.address?.line1, employee.address?.line2, employee.address?.city, employee.address?.state, employee.address?.postal_code, employee.address?.country]
        .filter(Boolean)
        .join(', ');
}

function Panel({ title, children }) {
    return (
        <Card title={title} dense className="overflow-hidden">
            <dl className="-mx-5 -my-4 divide-y divide-gray-100 sm:-mx-6 sm:-my-5">{children}</dl>
        </Card>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex items-baseline justify-between gap-4 px-4 py-2 text-sm">
            <dt className="text-gray-500">{label}</dt>
            <dd className="text-right text-gray-800">{value || <span className="text-gray-300">—</span>}</dd>
        </div>
    );
}
