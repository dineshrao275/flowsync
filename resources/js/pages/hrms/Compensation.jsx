import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';

const TYPES = [
    { value: 'earning', label: 'Earning' },
    { value: 'deduction', label: 'Deduction' },
    { value: 'employer_contribution', label: 'Employer contribution' },
    { value: 'reimbursement', label: 'Reimbursement' },
];

const CALC_TYPES = [
    { value: 'fixed', label: 'Fixed amount' },
    { value: 'percentage_of_ctc', label: '% of CTC' },
    { value: 'percentage_of_basic', label: '% of basic' },
    { value: 'formula', label: 'Formula (manual)' },
];

const emptyHead = { name: '', code: '', type: 'earning', calculation_type: 'fixed', default_value: '', is_taxable: true, is_prorated: true, is_active: true };
const emptyTemplate = { name: '', currency: 'USD', effective_from: '', description: '' };

/**
 * Pay heads, salary templates, and one person's pay basis.
 *
 * One page with internal gating like Holidays: reads ride
 * `hrms.compensation.view`, every mutation hides without
 * `hrms.compensation.manage` (the backend 403s regardless). Templates
 * version by effective date — money knobs never edit in place — and the
 * head-list editor replaces the whole set in one sync.
 */
export default function Compensation() {
    usePageTitle('Compensation');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canManage = can('hrms.compensation.manage');

    const [heads, setHeads] = useState(null);
    const [templates, setTemplates] = useState(null);
    const [employees, setEmployees] = useState([]);
    const [error, setError] = useState(null);

    const [headModal, setHeadModal] = useState(false);
    const [editingHead, setEditingHead] = useState(null);
    const [headForm, setHeadForm] = useState(emptyHead);
    const [headErrors, setHeadErrors] = useState({});

    const [templateModal, setTemplateModal] = useState(false);
    const [templateForm, setTemplateForm] = useState(emptyTemplate);
    const [templateErrors, setTemplateErrors] = useState({});
    const [openTemplate, setOpenTemplate] = useState(null);
    const [linkForm, setLinkForm] = useState([]);

    const [employeeId, setEmployeeId] = useState('');
    const [basis, setBasis] = useState(null);
    const [basisLoaded, setBasisLoaded] = useState(false);
    const [revisions, setRevisions] = useState([]);
    const [assignForm, setAssignForm] = useState({ structure_id: '', ctc_annual: '', effective_from: '', reason: '' });
    const [assignErrors, setAssignErrors] = useState({});
    const [reviseForm, setReviseForm] = useState({ to_ctc: '', effective_from: '', reason: '' });
    const [reviseErrors, setReviseErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Compensation' }]);
    }, [setCrumbs]);

    const fail = useCallback(
        (message) => (err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }

            setError(message);
        },
        [navigate],
    );

    const loadHeads = useCallback(() => {
        return api
            .get('/hrms/payroll/components')
            .then(({ data }) => setHeads(data.components ?? []))
            .catch(fail('Unable to load pay heads.'));
    }, [fail]);

    const loadTemplates = useCallback(() => {
        return api
            .get('/hrms/payroll/structures')
            .then(({ data }) => setTemplates(data.structures ?? []))
            .catch(fail('Unable to load salary templates.'));
    }, [fail]);

    useEffect(() => {
        loadHeads();
        loadTemplates();

        // The employee picker needs the directory; a reader without it still
        // gets heads and templates, so a 403 here degrades to no picker.
        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const loadBasis = useCallback(
        (id) => {
            if (!id) {
                setBasis(null);
                setRevisions([]);
                setBasisLoaded(true);
                return Promise.resolve();
            }

            setError(null);
            // Null first: without it the previous person's pay basis reads
            // as current while the new one loads.
            setBasis(null);
            setRevisions([]);
            setBasisLoaded(false);

            return Promise.all([
                api.get(`/hrms/payroll/employees/${id}/salary`).then(({ data }) => setBasis(data.assignment)),
                api.get(`/hrms/payroll/employees/${id}/revisions`).then(({ data }) => setRevisions(data.revisions ?? [])),
            ]).catch(fail('Unable to load this person’s pay basis.'))
                .finally(() => setBasisLoaded(true));
        },
        [fail],
    );

    useEffect(() => {
        loadBasis(employeeId);
    }, [employeeId, loadBasis]);

    function openHeadModal(head) {
        setEditingHead(head);
        setHeadForm(head ? { ...emptyHead, ...head, default_value: head.default_value ?? '' } : emptyHead);
        setHeadErrors({});
        setHeadModal(true);
    }

    async function saveHead(e) {
        e.preventDefault();
        setHeadErrors({});

        const payload = { ...headForm, default_value: headForm.default_value === '' ? 0 : headForm.default_value };

        try {
            if (editingHead) {
                await api.put(`/hrms/payroll/components/${editingHead.id}`, payload);
                toast.success('Pay head updated.');
            } else {
                await api.post('/hrms/payroll/components', payload);
                toast.success('Pay head created.');
            }

            setHeadModal(false);
            loadHeads();
        } catch (err) {
            setHeadErrors(fieldErrors(err));
        }
    }

    async function deleteHead(head) {
        if (!window.confirm(`Delete “${head.name}”? Starter and in-use heads refuse.`)) return;

        try {
            await api.delete(`/hrms/payroll/components/${head.id}`);
            toast.success('Pay head deleted.');
            loadHeads();
        } catch {
            setError('That head cannot be deleted.');
        }
    }

    async function saveTemplate(e) {
        e.preventDefault();
        setTemplateErrors({});

        try {
            await api.post('/hrms/payroll/structures', templateForm);
            toast.success('Template created.');
            setTemplateModal(false);
            setTemplateForm(emptyTemplate);
            loadTemplates();
        } catch (err) {
            setTemplateErrors(fieldErrors(err));
        }
    }

    function openLinks(template) {
        setOpenTemplate(template);
        setLinkForm((template.components ?? []).map((c) => ({ component_id: c.id, value: c.value, checked: true })));
    }

    function toggleLink(id) {
        setLinkForm((rows) => {
            if (rows.some((r) => r.component_id === id)) {
                return rows.map((r) => (r.component_id === id ? { ...r, checked: !r.checked } : r));
            }

            return [...rows, { component_id: id, value: '', checked: true }];
        });
    }

    async function saveLinks() {
        const links = linkForm
            .filter((r) => r.checked)
            .map((r, i) => ({ component_id: r.component_id, value: r.value === '' ? 0 : r.value, sequence: (i + 1) * 10 }));

        try {
            await api.put(`/hrms/payroll/structures/${openTemplate.id}/components`, { components: links });
            toast.success('Template heads updated.');
            setOpenTemplate(null);
            loadTemplates();
        } catch {
            setError('Unable to update the head list.');
        }
    }

    async function deleteTemplate(template) {
        if (!window.confirm(`Delete “${template.name}”? Priced templates refuse — deactivate instead.`)) return;

        try {
            await api.delete(`/hrms/payroll/structures/${template.id}`);
            toast.success('Template deleted.');
            loadTemplates();
        } catch {
            setError('That template prices people and cannot be deleted.');
        }
    }

    async function saveAssignment(e) {
        e.preventDefault();
        setAssignErrors({});

        try {
            await api.post(`/hrms/payroll/employees/${employeeId}/salary`, assignForm);
            toast.success('Salary assigned.');
            setAssignForm({ structure_id: '', ctc_annual: '', effective_from: '', reason: '' });
            loadBasis(employeeId);
        } catch (err) {
            setAssignErrors(fieldErrors(err));
        }
    }

    async function saveRevision(e) {
        e.preventDefault();
        setReviseErrors({});

        try {
            const { data } = await api.post(`/hrms/payroll/employees/${employeeId}/revisions`, reviseForm);
            toast.success(data.revision.status === 'applied' ? 'Raise applied immediately.' : 'Revision raised for approval.');
            setReviseForm({ to_ctc: '', effective_from: '', reason: '' });
            loadBasis(employeeId);
        } catch (err) {
            setReviseErrors(fieldErrors(err));
        }
    }

    async function applyRevision(id) {
        try {
            await api.post(`/hrms/payroll/employees/${employeeId}/revisions/${id}/apply`);
            toast.success('Revision applied.');
            loadBasis(employeeId);
        } catch {
            setError('That revision cannot be applied yet.');
        }
    }

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">Compensation</h2>
                <p className="mt-0.5 text-sm text-gray-500">Pay heads, salary templates, and one person’s pay basis.</p>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card
                title="Pay heads"
                dense
                actions={canManage && <Button size="sm" onClick={() => openHeadModal(null)}>New head</Button>}
            >
                {!heads ? (
                    <div className="flex justify-center py-8"><Spinner /></div>
                ) : heads.length === 0 ? (
                    <EmptyState title="No pay heads" />
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>Name</Th><Th>Code</Th><Th>Type</Th><Th>Calculation</Th><Th>Default</Th>{canManage && <Th><span className="sr-only">Actions</span></Th>}</tr>
                        </thead>
                        <tbody>
                            {heads.map((head) => (
                                <tr key={head.id}>
                                    <Td>{head.name}{head.is_system && <span className="ml-2 text-xs text-gray-400">starter</span>}</Td>
                                    <Td>{head.code ?? '—'}</Td>
                                    <Td>{head.type}</Td>
                                    <Td>{head.calculation_type}</Td>
                                    <Td>{head.default_value}</Td>
                                    {canManage && (
                                        <Td>
                                            <div className="flex gap-2">
                                                <Button size="sm" variant="secondary" onClick={() => openHeadModal(head)}>Edit</Button>
                                                <Button size="sm" variant="danger" onClick={() => deleteHead(head)}>Delete</Button>
                                            </div>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Card
                title="Salary templates"
                dense
                actions={canManage && <Button size="sm" onClick={() => setTemplateModal(true)}>New template</Button>}
            >
                {!templates ? (
                    <div className="flex justify-center py-8"><Spinner /></div>
                ) : templates.length === 0 ? (
                    <EmptyState title="No templates" />
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {templates.map((template) => (
                            <li key={template.id} className="py-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <span className="font-medium text-gray-900">{template.name}</span>
                                        <span className="ml-2 text-xs text-gray-400">
                                            {template.currency} · from {template.effective_from} · {(template.components ?? []).length} heads
                                            {!template.is_active && ' · inactive'}
                                        </span>
                                    </div>
                                    {canManage && (
                                        <div className="flex gap-2">
                                            <Button size="sm" variant="secondary" onClick={() => openLinks(template)}>Heads</Button>
                                            <Button size="sm" variant="danger" onClick={() => deleteTemplate(template)}>Delete</Button>
                                        </div>
                                    )}
                                </div>
                                <div className="mt-1 text-sm text-gray-500">
                                    {(template.components ?? []).map((c) => c.code ?? c.name).join(', ') || 'No heads yet.'}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card title="Employee pay" dense>
                <div className="mb-4 max-w-sm">
                    <Select label="Employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                        <option value="">Select a person…</option>
                        {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                    </Select>
                </div>

                {employeeId && (
                    <>
                        {!basisLoaded ? (
                            <div className="mb-4 flex justify-center py-8"><Spinner /></div>
                        ) : basis ? (
                            <div className="mb-4 rounded-lg border border-gray-200 p-4 text-sm">
                                <p className="font-medium text-gray-900">{basis.structure?.name} · {basis.ctc_annual} annual</p>
                                <p className="mt-1 text-gray-500">
                                    {basis.monthly_ctc} monthly · gross {basis.gross_monthly} · from {basis.effective_from}
                                </p>
                            </div>
                        ) : (
                            <p className="mb-4 text-sm text-gray-500">No pay basis yet — pricing starts with the form below.</p>
                        )}

                        {canManage && (
                            <form onSubmit={saveAssignment} className="mb-6 grid gap-3 sm:grid-cols-2">
                                <Select label="Template" value={assignForm.structure_id} error={assignErrors.structure_id} onChange={(e) => setAssignForm({ ...assignForm, structure_id: e.target.value })} required>
                                    <option value="">Select a template…</option>
                                    {(templates ?? []).filter((t) => t.is_active).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                                </Select>
                                <Input label="Annual CTC" value={assignForm.ctc_annual} error={assignErrors.ctc_annual} onChange={(e) => setAssignForm({ ...assignForm, ctc_annual: e.target.value })} required />
                                <Input label="Effective from" type="date" value={assignForm.effective_from} error={assignErrors.effective_from} onChange={(e) => setAssignForm({ ...assignForm, effective_from: e.target.value })} required />
                                <Input label="Reason" value={assignForm.reason} error={assignErrors.reason} onChange={(e) => setAssignForm({ ...assignForm, reason: e.target.value })} />
                                <div className="sm:col-span-2"><Button type="submit">Assign salary</Button></div>
                            </form>
                        )}

                        <h4 className="mb-2 text-sm font-semibold text-gray-900">Revisions</h4>
                        {revisions.length === 0 ? (
                            <p className="text-sm text-gray-500">No revisions yet.</p>
                        ) : (
                            <ul className="mb-4 divide-y divide-gray-100 text-sm">
                                {revisions.map((r) => (
                                    <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                        <span>{r.from_ctc} → {r.to_ctc} · from {r.effective_from} · <span className="text-gray-500">{r.status}</span></span>
                                        {canManage && r.status === 'draft' && (
                                            <Button size="sm" variant="secondary" onClick={() => applyRevision(r.id)}>Apply</Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {canManage && (
                            <form onSubmit={saveRevision} className="grid gap-3 sm:grid-cols-3">
                                <Input label="New annual CTC" value={reviseForm.to_ctc} error={reviseErrors.to_ctc} onChange={(e) => setReviseForm({ ...reviseForm, to_ctc: e.target.value })} required />
                                <Input label="Effective from" type="date" value={reviseForm.effective_from} error={reviseErrors.effective_from} onChange={(e) => setReviseForm({ ...reviseForm, effective_from: e.target.value })} required />
                                <Input label="Reason" value={reviseForm.reason} error={reviseErrors.reason} onChange={(e) => setReviseForm({ ...reviseForm, reason: e.target.value })} />
                                <div className="sm:col-span-3"><Button type="submit" variant="secondary">Raise revision</Button></div>
                            </form>
                        )}
                    </>
                )}
            </Card>

            <Modal open={headModal} onClose={() => setHeadModal(false)} title={editingHead ? 'Edit pay head' : 'New pay head'}>
                <form onSubmit={saveHead} className="grid gap-3">
                    <Input label="Name" value={headForm.name} error={headErrors.name} onChange={(e) => setHeadForm({ ...headForm, name: e.target.value })} required />
                    <Input label="Code" value={headForm.code} error={headErrors.code} onChange={(e) => setHeadForm({ ...headForm, code: e.target.value })} />
                    <div className="grid grid-cols-2 gap-3">
                        <Select label="Type" value={headForm.type} error={headErrors.type} onChange={(e) => setHeadForm({ ...headForm, type: e.target.value })}>
                            {TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </Select>
                        <Select label="Calculation" value={headForm.calculation_type} error={headErrors.calculation_type} onChange={(e) => setHeadForm({ ...headForm, calculation_type: e.target.value })}>
                            {CALC_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </Select>
                    </div>
                    <Input label="Default value" value={headForm.default_value} error={headErrors.default_value} onChange={(e) => setHeadForm({ ...headForm, default_value: e.target.value })} />
                    <div className="flex gap-4 text-sm">
                        <label className="flex items-center gap-2"><input type="checkbox" checked={headForm.is_taxable} onChange={(e) => setHeadForm({ ...headForm, is_taxable: e.target.checked })} /> Taxable</label>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={headForm.is_prorated} onChange={(e) => setHeadForm({ ...headForm, is_prorated: e.target.checked })} /> Prorated</label>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={headForm.is_active} onChange={(e) => setHeadForm({ ...headForm, is_active: e.target.checked })} /> Active</label>
                    </div>
                    <div><Button type="submit">{editingHead ? 'Save head' : 'Create head'}</Button></div>
                </form>
            </Modal>

            <Modal open={templateModal} onClose={() => setTemplateModal(false)} title="New salary template">
                <form onSubmit={saveTemplate} className="grid gap-3">
                    <Input label="Name" value={templateForm.name} error={templateErrors.name} onChange={(e) => setTemplateForm({ ...templateForm, name: e.target.value })} required />
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Currency" value={templateForm.currency} error={templateErrors.currency} onChange={(e) => setTemplateForm({ ...templateForm, currency: e.target.value })} maxLength={3} />
                        <Input label="Effective from" type="date" value={templateForm.effective_from} error={templateErrors.effective_from} onChange={(e) => setTemplateForm({ ...templateForm, effective_from: e.target.value })} required />
                    </div>
                    <Input label="Description" value={templateForm.description} error={templateErrors.description} onChange={(e) => setTemplateForm({ ...templateForm, description: e.target.value })} />
                    <div><Button type="submit">Create template</Button></div>
                </form>
            </Modal>

            <Modal open={!!openTemplate} onClose={() => setOpenTemplate(null)} title={`Heads — ${openTemplate?.name ?? ''}`} subtitle="The whole set, replaced in one save.">
                <div className="grid gap-2">
                    {(heads ?? []).map((head) => {
                        const row = linkForm.find((r) => r.component_id === head.id);
                        return (
                            <label key={head.id} className="flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                <input type="checkbox" checked={!!row?.checked} onChange={() => toggleLink(head.id)} />
                                <span className="flex-1">{head.name} <span className="text-gray-400">· {head.type}</span></span>
                                <input
                                    className="w-28 rounded-lg border border-gray-300 px-2 py-1 text-sm"
                                    placeholder="value"
                                    aria-label={`Value for ${head.name}`}
                                    value={row?.value ?? ''}
                                    disabled={!row?.checked}
                                    onChange={(e) => setLinkForm((rows) => rows.map((r) => (r.component_id === head.id ? { ...r, value: e.target.value } : r)))}
                                />
                            </label>
                        );
                    })}
                </div>
                <div className="mt-4"><Button onClick={saveLinks}>Save head list</Button></div>
            </Modal>
        </div>
    );
}
