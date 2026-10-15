import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import EmptyState from '../../components/ui/EmptyState';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import TemplateEditor from './TemplateEditor';

const STATUSES = [
    { value: 'not_started', key: 'stNotStarted' },
    { value: 'in_progress', key: 'stInProgress' },
    { value: 'completed', key: 'stCompleted' },
    { value: 'cancelled', key: 'stCancelled' },
];

function ProgressBar({ value }) {
    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-24 overflow-hidden rounded-full bg-gray-100">
                <div className="h-full rounded-full bg-[var(--accent-soft)]0" style={{ width: `${value}%` }} />
            </div>
            <span className="text-xs text-gray-500">{value}%</span>
        </div>
    );
}

/**
 * The onboarding runs: who is being hired, how far along they are, and the
 * template catalogue they start from.
 *
 * The case rows carry their progress inline because the list is the screen
 * an HR manager scans every morning: a case at 40% with three mandatory
 * items open reads differently from a case at 90%, and a second request per
 * row to learn that would make the scan unusable.
 */
export default function OnboardingCases() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.onboarding.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const [status, setStatus] = useState('');
    const [cases, setCases] = useState(null);
    const [templates, setTemplates] = useState([]);
    const [employees, setEmployees] = useState(null);
    const [error, setError] = useState(null);
    const [starting, setStarting] = useState(false);
    const [managingTemplates, setManagingTemplates] = useState(false);
    const [editingTemplateId, setEditingTemplateId] = useState('');
    const [newTemplateName, setNewTemplateName] = useState('');
    const [form, setForm] = useState({ employee_id: '', template_id: '' });
    const [formErrors, setFormErrors] = useState({});

    const canManage = can('permission:hrms.onboarding.manage');
    const canPickEmployee = can('permission:hrms.employees.view');

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.onboarding.pageTitle') }]);
    }, [setCrumbs, t]);

    const load = useCallback(() => {
        setError(null);

        const params = status ? { status } : {};

        return api
            .get('/hrms/onboarding/cases', { params })
            .then(({ data }) => setCases(data.cases ?? []))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.onboarding.loadFailed'));
            });
    }, [status, navigate, t]);

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

            toast.success(t('hrms.onboarding.started'));
            setForm({ employee_id: '', template_id: '' });
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
            toast.success(t('hrms.onboarding.templateCreated'));
        } catch (err) {
            toast.error(fieldErrors(err).form ?? t('hrms.onboarding.templateFailed'));
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.onboarding.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {cases ? (cases.length === 1 ? t('hrms.onboarding.casesOne') : t('hrms.onboarding.cases', { count: cases.length })) : '—'}
                    </p>
                </div>

                {canManage && (
                    <Button variant="secondary" onClick={() => setManagingTemplates(true)}>
                        {t('hrms.onboarding.manageTemplates')}
                    </Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {canManage && canPickEmployee && (
                <form onSubmit={start} className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200/70 bg-white p-3">
                    <Select label={t('hrms.onboarding.employee')} value={form.employee_id} onChange={(e) => setForm((f) => ({ ...f, employee_id: e.target.value }))} className="min-w-52 flex-1">
                        <option value="">{t('hrms.onboarding.selectEmployee')}</option>
                        {(employees ?? []).map((employee) => (
                            <option key={employee.id} value={employee.id}>
                                {employee.name}
                            </option>
                        ))}
                    </Select>
                    <Select label={t('hrms.onboarding.template')} value={form.template_id} onChange={(e) => setForm((f) => ({ ...f, template_id: e.target.value }))} className="min-w-52 flex-1">
                        <option value="">{t('hrms.onboarding.selectTemplate')}</option>
                        {templates.filter((t) => t.is_active).map((template) => (
                            <option key={template.id} value={template.id}>
                                {template.name}
                            </option>
                        ))}
                    </Select>
                    <Button type="submit" loading={starting} disabled={!form.employee_id || !form.template_id}>
                        {t('hrms.onboarding.startCase')}
                    </Button>
                    {formErrors.form && <Alert>{formErrors.form}</Alert>}
                </form>
            )}

            <div className="grid gap-3 rounded-xl border border-gray-200/70 bg-white p-3 sm:grid-cols-2 lg:grid-cols-4">
                <Select label={t('hrms.onboarding.status')} value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">{t('hrms.onboarding.anyStatus')}</option>
                    {STATUSES.map((s) => (
                        <option key={s.value} value={s.value}>
                            {t(`hrms.onboarding.${s.key}`)}
                        </option>
                    ))}
                </Select>
            </div>

            {!cases ? (
                <div className="flex justify-center py-10">
                    <Spinner />
                </div>
            ) : cases.length === 0 ? (
                <EmptyState title={t('hrms.onboarding.emptyTitle')} description={t('hrms.onboarding.emptyHint')} />
            ) : (
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('hrms.onboarding.thEmployee')}</Th>
                            <Th>{t('hrms.onboarding.thTemplate')}</Th>
                            <Th>{t('hrms.onboarding.thStatus')}</Th>
                            <Th>{t('hrms.onboarding.thProgress')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {cases.map((row) => (
                            <tr key={row.id}>
                                <Td>
                                    <Link to={`/hrms/onboarding/cases/${row.id}`} className="font-medium text-[var(--accent-soft-text)] hover:underline">
                                        {row.employee?.name ?? '—'}
                                    </Link>
                                </Td>
                                <Td>{row.template?.name ?? '—'}</Td>
                                <Td className="capitalize">{row.status_label ?? row.status}</Td>
                                <Td>
                                    <ProgressBar value={row.progress?.percent ?? 0} />
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}

            <Modal open={managingTemplates} onClose={() => setManagingTemplates(false)} title={t('hrms.onboarding.templatesTitle')} size="lg">
                <div className="space-y-4 px-6 py-5">
                <form onSubmit={createTemplate} className="flex items-end gap-2">
                    <Input label={t('hrms.onboarding.newTemplate')} placeholder={t('hrms.onboarding.newTemplatePlaceholder')} value={newTemplateName} onChange={(e) => setNewTemplateName(e.target.value)} />
                    <Button type="submit" disabled={!newTemplateName.trim()}>
                        {t('hrms.onboarding.create')}
                    </Button>
                </form>
                {templates.length > 0 && (
                    <Select label={t('hrms.onboarding.editing')} value={editingTemplateId} onChange={(e) => setEditingTemplateId(e.target.value)} className="mb-3">
                        {templates.map((template) => (
                            <option key={template.id} value={template.id}>
                                {template.name}
                                {template.is_active ? '' : t('hrms.onboarding.retired')}
                            </option>
                        ))}
                    </Select>
                )}
                {editingTemplateId ? (
                    <TemplateEditor key={editingTemplateId} templateId={Number(editingTemplateId)} onSaved={loadTemplates} />
                ) : (
                    <p className="py-6 text-center text-sm text-gray-400">{t('hrms.onboarding.noTemplates')}</p>
                )}
                </div>
            </Modal>
        </div>
    );
}
