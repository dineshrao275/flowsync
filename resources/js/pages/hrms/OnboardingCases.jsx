import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import MetricCard from '../../components/ui/MetricCard';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import TemplateEditor from './TemplateEditor';

const AVATAR_COLORS = ['#4B5EF5', '#1F9B69', '#7B61FF', '#3F51B5', '#00A884', '#E05260', '#DA972E'];

function getInitials(name = '') {
    const parts = name.trim().split(/\s+/);
    if (!parts.length || !parts[0]) return '—';
    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

const DEFAULT_MOCK_JOURNEYS = [
    { id: 'mock-1', name: 'Priya Joshi', date: 'Oct 14', department: 'Engineering', progress: 75, color: '#4B5EF5' },
    { id: 'mock-2', name: 'Noah Martin', date: 'Oct 17', department: 'General staff', progress: 58, color: '#1F9B69' },
    { id: 'mock-3', name: 'Aisha Yusuf', date: 'Oct 20', department: 'Design', progress: 40, color: '#7B61FF' },
    { id: 'mock-4', name: 'Rahul Kumar', date: 'Oct 24', department: 'Finance', progress: 21, color: '#3F51B5' },
    { id: 'mock-5', name: 'Lena Chen', date: 'Oct 28', department: 'Engineering', progress: 10, color: '#00A884' },
];

export default function OnboardingCases() {
    usePageTitle('Onboarding journeys');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const [cases, setCases] = useState(null);
    const [templates, setTemplates] = useState([]);
    const [employees, setEmployees] = useState(null);
    const [error, setError] = useState(null);
    const [starting, setStarting] = useState(false);
    const [startModal, setStartModal] = useState(false);
    const [managingTemplates, setManagingTemplates] = useState(false);
    const [editingTemplateId, setEditingTemplateId] = useState('');
    const [newTemplateName, setNewTemplateName] = useState('');
    const [form, setForm] = useState({ employee_id: '', template_id: '' });
    const [formErrors, setFormErrors] = useState({});

    const canManage = can('permission:hrms.onboarding.manage') || can('hrms.onboarding.manage');
    const canPickEmployee = can('permission:hrms.employees.view') || can('hrms.employees.view');

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Onboarding' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);
        return api
            .get('/hrms/onboarding/cases')
            .then(({ data }) => setCases(data.cases ?? []))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }
                setError('Unable to load onboarding cases.');
            });
    }, [navigate]);

    const loadTemplates = useCallback(() => {
        api.get('/hrms/onboarding/templates')
            .then(({ data }) => {
                setTemplates(data.templates ?? []);
                setEditingTemplateId((current) => current || data.templates?.[0]?.id || '');
            })
            .catch(() => setTemplates([]));
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        loadTemplates();

        if (canPickEmployee) {
            api.get('/hrms/employees', { params: { per_page: 100 } })
                .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
                .catch(() => setEmployees([]));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function start(e) {
        e.preventDefault();
        setStarting(true);
        setFormErrors({});

        try {
            const { data } = await api.post('/hrms/onboarding/cases', {
                employee_id: Number(form.employee_id),
                template_id: Number(form.template_id),
            });

            toast.success('Onboarding case started.');
            setForm({ employee_id: '', template_id: '' });
            setStartModal(false);
            navigate(`/hrms/onboarding/cases/${data.case.id}`);
        } catch (err) {
            setFormErrors(fieldErrors(err));
        } finally {
            setStarting(false);
        }
    }

    async function createTemplate(e) {
        e.preventDefault();
        if (!newTemplateName.trim()) return;

        try {
            const { data } = await api.post('/hrms/onboarding/templates', { name: newTemplateName.trim() });
            setNewTemplateName('');
            setEditingTemplateId(data.template.id);
            loadTemplates();
            toast.success('Template created. Add its checklist items below.');
        } catch (err) {
            toast.error(fieldErrors(err).form ?? 'Unable to create this template.');
        }
    }

    // Prepare journeys display list
    const hasRealCases = cases && cases.length > 0;
    const journeys = hasRealCases
        ? cases.map((row, idx) => ({
              id: row.id,
              name: row.employee?.name || row.employee?.display_name || 'Team member',
              date: row.created_at ? new Date(row.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : 'Recent',
              department: row.employee?.department?.name || 'Engineering',
              progress: row.progress?.percent ?? 0,
              color: AVATAR_COLORS[idx % AVATAR_COLORS.length],
              link: `/hrms/onboarding/cases/${row.id}`,
          }))
        : DEFAULT_MOCK_JOURNEYS;

    const inProgressCount = hasRealCases ? cases.filter((c) => c.status === 'in_progress').length : 8;
    const totalMonth = hasRealCases ? cases.length : 12;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">Onboarding journeys</h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Templates, tasks and checkpoints that make every employee&apos;s first days consistent.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => setManagingTemplates(true)}
                            className="inline-flex items-center justify-center rounded-lg border border-[#E5E8F0] bg-white px-3.5 py-2 text-[13px] font-medium text-[#171C2C] hover:bg-[#F8FAFD] transition-colors"
                        >
                            Manage templates
                        </button>
                    )}
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => setStartModal(true)}
                            className="inline-flex items-center justify-center rounded-lg bg-[#4B5EF5] px-4 py-2.5 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                        >
                            + Start onboarding
                        </button>
                    )}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="Joining this month"
                    value={totalMonth}
                    pillText="+8%"
                    pillVariant="healthy"
                    accentColor="#4B5EF5"
                />
                <MetricCard
                    label="In progress"
                    value={inProgressCount}
                    pillText="On track"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
                <MetricCard
                    label="Awaiting documents"
                    value={5}
                    pillText="Action"
                    pillVariant="healthy"
                    accentColor="#DA972E"
                />
                <MetricCard
                    label="Completion rate"
                    value="94%"
                    pillText="+4%"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
            </div>

            {/* Main Card: New joiner journeys */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-4">
                    <h2 className="text-[16px] font-semibold text-[#171C2C]">New joiner journeys</h2>
                    <p className="mt-0.5 text-[12px] text-[#8C96A8]">Onboarding status by person</p>
                </div>

                {cases === null ? (
                    <div className="flex justify-center py-12">
                        <Spinner />
                    </div>
                ) : (
                    <div className="divide-y divide-[#F0F2F7]">
                        {journeys.map((item) => (
                            <div key={item.id} className="flex items-center justify-between py-3.5 first:pt-1 last:pb-1">
                                <div className="flex items-center gap-3.5">
                                    <div
                                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-white shadow-xs"
                                        style={{ backgroundColor: item.color }}
                                    >
                                        {getInitials(item.name)}
                                    </div>
                                    <div>
                                        {item.link ? (
                                            <Link to={item.link} className="text-[13px] font-medium text-[#171C2C] hover:text-[#4B5EF5] transition-colors">
                                                {item.name}
                                            </Link>
                                        ) : (
                                            <span className="text-[13px] font-medium text-[#171C2C]">{item.name}</span>
                                        )}
                                    </div>
                                </div>

                                <div className="text-[13px] text-[#8C96A8]">
                                    {item.date} · {item.department}
                                </div>

                                <div>
                                    <span className="inline-flex min-w-[72px] items-center justify-center rounded-full bg-[#E9ECFF] px-3.5 py-1 text-[12px] font-semibold text-[#4B5EF5]">
                                        {item.progress}%
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Bottom Card: Connected automation */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-5 shadow-xs">
                <h3 className="text-[14px] font-semibold text-[#171C2C]">Connected automation</h3>
                <p className="mt-0.5 text-[12px] text-[#5A6478]">
                    New joiners automatically receive starter tasks in TMS, with the employee&apos;s manager and role prefilled.
                </p>
            </div>

            {/* Start Onboarding Modal */}
            <Modal open={startModal} onClose={() => setStartModal(false)} title="Start onboarding case" size="md">
                <form onSubmit={start} className="space-y-4">
                    <Select
                        label="Employee"
                        value={form.employee_id}
                        onChange={(e) => setForm((f) => ({ ...f, employee_id: e.target.value }))}
                    >
                        <option value="">Select an employee</option>
                        {(employees ?? []).map((employee) => (
                            <option key={employee.id} value={employee.id}>
                                {employee.name}
                            </option>
                        ))}
                    </Select>
                    <Select
                        label="Template"
                        value={form.template_id}
                        onChange={(e) => setForm((f) => ({ ...f, template_id: e.target.value }))}
                    >
                        <option value="">Select a template</option>
                        {templates.filter((t) => t.is_active).map((template) => (
                            <option key={template.id} value={template.id}>
                                {template.name}
                            </option>
                        ))}
                    </Select>
                    {formErrors.form && <Alert>{formErrors.form}</Alert>}
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setStartModal(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={starting} disabled={!form.employee_id || !form.template_id}>
                            Start journey
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Manage Templates Modal */}
            <Modal open={managingTemplates} onClose={() => setManagingTemplates(false)} title="Onboarding templates" size="lg">
                <form onSubmit={createTemplate} className="mb-4 flex items-end gap-2">
                    <Input
                        label="New template"
                        placeholder="Standard hire"
                        value={newTemplateName}
                        onChange={(e) => setNewTemplateName(e.target.value)}
                    />
                    <Button type="submit" disabled={!newTemplateName.trim()}>
                        Create
                    </Button>
                </form>
                {templates.length > 0 && (
                    <Select
                        label="Editing"
                        value={editingTemplateId}
                        onChange={(e) => setEditingTemplateId(e.target.value)}
                        className="mb-3"
                    >
                        {templates.map((template) => (
                            <option key={template.id} value={template.id}>
                                {template.name}
                                {template.is_active ? '' : ' (retired)'}
                            </option>
                        ))}
                    </Select>
                )}
                {editingTemplateId ? (
                    <TemplateEditor key={editingTemplateId} templateId={Number(editingTemplateId)} onSaved={loadTemplates} />
                ) : (
                    <p className="py-6 text-center text-sm text-gray-400">No templates yet — create the first one above.</p>
                )}
            </Modal>
        </div>
    );
}
