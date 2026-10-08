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

const emptyConfig = { country: '', region: '', name: '', code: '', is_active: true, config_json: '' };
const emptyDeclaration = { employee_id: '', fiscal_year: String(new Date().getFullYear()), section: '', declared_amount: '', proof_document_id: '' };
const emptyProfile = { pan: '', aadhaar: '', uan: '', esi_number: '', pf_number: '', pt_state: '', lwf_registration: false, bank_name: '', bank_account: '', bank_ifsc: '' };

/**
 * Statutory compliance: rulebooks, identifiers, claims, and projections.
 *
 * Restricted data throughout — the page says so up front, and every
 * cleartext reveal writes a read receipt the audit log keeps. Reads ride
 * the statutory module; mutations hide without
 * `hrms.payroll.statutory.manage` (the backend 403s regardless). Claims
 * are the one self-service corner: anyone files their own, only HR
 * verifies.
 */
export default function Statutory() {
    usePageTitle('Statutory');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canManage = can('hrms.payroll.statutory.manage');

    const [configs, setConfigs] = useState(null);
    const [declarations, setDeclarations] = useState(null);
    const [employees, setEmployees] = useState([]);
    const [error, setError] = useState(null);

    const [configModal, setConfigModal] = useState(false);
    const [editingConfig, setEditingConfig] = useState(null);
    const [configForm, setConfigForm] = useState(emptyConfig);
    const [configErrors, setConfigErrors] = useState({});

    const [employeeId, setEmployeeId] = useState('');
    const [profile, setProfile] = useState(null);
    const [profileLoaded, setProfileLoaded] = useState(false);
    const [profileForm, setProfileForm] = useState(emptyProfile);
    const [profileErrors, setProfileErrors] = useState({});
    const [revealed, setRevealed] = useState(null);

    const [declModal, setDeclModal] = useState(false);
    const [declForm, setDeclForm] = useState(emptyDeclaration);
    const [declErrors, setDeclErrors] = useState({});

    const [projEmployee, setProjEmployee] = useState('');
    const [projYear, setProjYear] = useState(String(new Date().getFullYear()));
    const [projection, setProjection] = useState(null);
    const [surrendering, setSurrendering] = useState(null);
    const [challan, setChallan] = useState('');
    const [challanErrors, setChallanErrors] = useState({});

    const [recomputeForm, setRecomputeForm] = useState({ run_id: '', force: false });
    const [recomputeResult, setRecomputeResult] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Statutory' }]);
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

    const loadConfigs = useCallback(() => {
        return api
            .get('/hrms/payroll/statutory/configurations')
            .then(({ data }) => setConfigs(data.configurations ?? []))
            .catch((err) => {
                // A failure is not an empty rulebook: 403 leaves the page,
                // anything else keeps the list empty but says so.
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setConfigs([]);
                setError('Unable to load rulebooks.');
            });
    }, [navigate]);

    const loadDeclarations = useCallback(() => {
        return api
            .get('/hrms/payroll/statutory/declarations')
            .then(({ data }) => setDeclarations(data.declarations ?? []))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setDeclarations([]);
                setError('Unable to load declarations.');
            });
    }, [navigate]);

    useEffect(() => {
        if (canManage) {
            loadConfigs();
            loadDeclarations();
        } else {
            setConfigs([]);
            setDeclarations([]);
        }

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const loadProfile = useCallback(
        (id) => {
            if (!id) {
                setProfile(null);
                setRevealed(null);
                setProfileLoaded(true);
                return Promise.resolve();
            }

            // Null means loading until the flag says otherwise — without
            // it the card briefly presents "nothing on record" as fact.
            setProfileLoaded(false);

            return api
                .get(`/hrms/payroll/statutory/profiles/${id}`)
                .then(({ data }) => {
                    setProfile(data.profile);
                    setRevealed(null);
                    setProfileLoaded(true);
                })
                .catch((err) => {
                    setProfileLoaded(true);
                    fail('Unable to load this profile.')(err);
                });
        },
        [fail],
    );

    useEffect(() => {
        loadProfile(employeeId);
    }, [employeeId, loadProfile]);

    function openConfigModal(config) {
        setEditingConfig(config);
        setConfigForm(
            config
                ? { country: config.country, region: config.region ?? '', name: config.name, code: config.code, is_active: config.is_active, config_json: JSON.stringify(config.config ?? {}, null, 2) }
                : emptyConfig,
        );
        setConfigErrors({});
        setConfigModal(true);
    }

    async function saveConfig(e) {
        e.preventDefault();
        setConfigErrors({});

        let parsed = null;

        if (configForm.config_json.trim() !== '') {
            try {
                parsed = JSON.parse(configForm.config_json);
            } catch {
                setConfigErrors({ config_json: 'That is not valid JSON.' });
                return;
            }
        }

        const payload = {
            ...(editingConfig ? {} : { country: configForm.country, code: configForm.code }),
            region: configForm.region || null,
            name: configForm.name,
            is_active: configForm.is_active,
            config: parsed,
        };

        try {
            if (editingConfig) {
                await api.put(`/hrms/payroll/statutory/configurations/${editingConfig.id}`, payload);
                toast.success('Rulebook updated.');
            } else {
                await api.post('/hrms/payroll/statutory/configurations', payload);
                toast.success('Rulebook created.');
            }

            setConfigModal(false);
            loadConfigs();
        } catch (err) {
            setConfigErrors(fieldErrors(err));
        }
    }

    async function deleteConfig(config) {
        if (!window.confirm(`Delete “${config.name}”? Locked payslips price from snapshots, so history is safe.`)) return;

        try {
            await api.delete(`/hrms/payroll/statutory/configurations/${config.id}`);
            toast.success('Rulebook deleted.');
            loadConfigs();
        } catch {
            setError('That rulebook cannot be deleted.');
        }
    }

    async function saveProfile(e) {
        e.preventDefault();
        setProfileErrors({});

        try {
            const { data } = await api.put(`/hrms/payroll/statutory/profiles/${employeeId}`, profileForm);
            toast.success('Profile saved.');
            setProfile(data.profile);
            setProfileForm(emptyProfile);
        } catch (err) {
            setProfileErrors(fieldErrors(err));
        }
    }

    async function revealProfile() {
        try {
            const { data } = await api.post(`/hrms/payroll/statutory/profiles/${employeeId}/reveal`, {});
            setRevealed(data.profile);
            toast.success('Cleartext shown — this read is logged.');
        } catch {
            setError('That profile cannot be revealed.');
        }
    }

    async function saveDeclaration(e) {
        e.preventDefault();
        setDeclErrors({});

        try {
            await api.post('/hrms/payroll/statutory/declarations', declForm);
            toast.success('Claim filed as a draft.');
            setDeclModal(false);
            setDeclForm(emptyDeclaration);
            loadDeclarations();
        } catch (err) {
            setDeclErrors(fieldErrors(err));
        }
    }

    async function decideDeclaration(declaration, verdict) {
        try {
            await api.post(`/hrms/payroll/statutory/declarations/${declaration.id}/${verdict}`, {});
            toast.success(`Claim ${verdict === 'verify' ? 'verified' : verdict === 'reject' ? 'rejected' : 'submitted'}.`);
            loadDeclarations();
        } catch {
            setError('That transition was refused — the claim may have moved already.');
        }
    }

    async function runProjection(e) {
        e?.preventDefault();

        try {
            const { data } = await api.post('/hrms/payroll/statutory/tds-projects', {
                employee_id: Number(projEmployee),
                fiscal_year: Number(projYear),
            });
            setProjection(data);
        } catch {
            setError('That year cannot be projected — it may hold no payslip.');
        }
    }

    async function surrender(e) {
        e.preventDefault();
        setChallanErrors({});

        try {
            await api.post(`/hrms/payroll/statutory/tds-projects/${surrendering.id}/surrender`, { challan_ref: challan });
            toast.success('Shortfall surrendered.');
            setSurrendering(null);
            setChallan('');
            runProjection();
        } catch (err) {
            setChallanErrors(fieldErrors(err));
        }
    }

    async function recompute(e) {
        e.preventDefault();

        try {
            const { data } = await api.post('/hrms/payroll/statutory/recompute', {
                run_id: Number(recomputeForm.run_id),
                force: recomputeForm.force,
            });
            setRecomputeResult(data.summary);
            toast.success('Statutory lines recomputed.');
        } catch {
            setError('That run refuses a recompute — only review runs accept one.');
        }
    }

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">Statutory compliance</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    Restricted data: identifiers read masked, cleartext reveals are logged against your account,
                    and every figure here is advisory until a statutory expert confirms the rulebook.
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            {canManage && (
                <Card
                    title="Jurisdiction rulebooks"
                    dense
                    actions={<Button size="sm" onClick={() => openConfigModal(null)}>New rulebook</Button>}
                >
                    {!configs ? (
                        <div className="flex justify-center py-8"><Spinner /></div>
                    ) : configs.length === 0 ? (
                        <EmptyState title="No rulebooks" hint="Copy a preset into a tenant-owned row first." />
                    ) : (
                        <Table>
                            <thead>
                                <tr><Th>Code</Th><Th>Country</Th><Th>Region</Th><Th>Active</Th><Th><span className="sr-only">Actions</span></Th></tr>
                            </thead>
                            <tbody>
                                {configs.map((config) => (
                                    <tr key={config.id}>
                                        <Td>{config.code}</Td>
                                        <Td>{config.country}</Td>
                                        <Td>{config.region ?? '—'}</Td>
                                        <Td>{config.is_active ? 'yes' : 'no'}</Td>
                                        <Td>
                                            <div className="flex gap-2">
                                                <Button size="sm" variant="secondary" onClick={() => openConfigModal(config)}>Edit</Button>
                                                <Button size="sm" variant="danger" onClick={() => deleteConfig(config)}>Delete</Button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}

            <Card title="Identifiers" dense>
                <div className="mb-4 max-w-sm">
                    <Select label="Employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                        <option value="">Select a person…</option>
                        {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                    </Select>
                </div>

                {employeeId && (
                    <>
                        {!profileLoaded ? (
                            <div className="flex justify-center py-8"><Spinner /></div>
                        ) : !profile ? (
                            <p className="text-sm text-gray-500">No identifiers filed — nothing on record, not a missing file.</p>
                        ) : (
                            <div className="mb-4 rounded-lg border border-gray-200 p-4 text-sm">
                                <div className="grid gap-1 sm:grid-cols-2">
                                    <span>PAN <strong>{profile.pan ?? '—'}</strong></span>
                                    <span>Aadhaar <strong>{profile.aadhaar_last4 ?? '—'}</strong></span>
                                    <span>UAN <strong>{profile.uan ?? '—'}</strong></span>
                                    <span>Account <strong>{profile.bank_account ?? '—'}</strong></span>
                                </div>
                                {canManage && (
                                    <div className="mt-3">
                                        <Button size="sm" variant="secondary" onClick={revealProfile}>Reveal cleartext (logged)</Button>
                                    </div>
                                )}
                                {revealed && (
                                    <div className="mt-3 rounded-lg bg-amber-50 p-3 text-sm">
                                        <p className="font-medium text-amber-900">Cleartext — this read is in the access log.</p>
                                        <p className="mt-1">PAN {revealed.pan ?? '—'} · UAN {revealed.uan ?? '—'} · Account {revealed.bank_account ?? '—'}</p>
                                    </div>
                                )}
                            </div>
                        )}

                        {canManage && (
                            <form onSubmit={saveProfile} className="grid gap-3 sm:grid-cols-3">
                                <Input label="PAN" value={profileForm.pan} error={profileErrors.pan} onChange={(e) => setProfileForm({ ...profileForm, pan: e.target.value })} />
                                <Input label="Aadhaar (12 digits, stored as 4)" value={profileForm.aadhaar} error={profileErrors.aadhaar} onChange={(e) => setProfileForm({ ...profileForm, aadhaar: e.target.value })} />
                                <Input label="UAN" value={profileForm.uan} error={profileErrors.uan} onChange={(e) => setProfileForm({ ...profileForm, uan: e.target.value })} />
                                <Input label="ESI number" value={profileForm.esi_number} error={profileErrors.esi_number} onChange={(e) => setProfileForm({ ...profileForm, esi_number: e.target.value })} />
                                <Input label="PF number" value={profileForm.pf_number} error={profileErrors.pf_number} onChange={(e) => setProfileForm({ ...profileForm, pf_number: e.target.value })} />
                                <Input label="PT state" value={profileForm.pt_state} error={profileErrors.pt_state} onChange={(e) => setProfileForm({ ...profileForm, pt_state: e.target.value })} />
                                <Input label="Bank name" value={profileForm.bank_name} error={profileErrors.bank_name} onChange={(e) => setProfileForm({ ...profileForm, bank_name: e.target.value })} />
                                <Input label="Bank account" value={profileForm.bank_account} error={profileErrors.bank_account} onChange={(e) => setProfileForm({ ...profileForm, bank_account: e.target.value })} />
                                <Input label="Bank IFSC" value={profileForm.bank_ifsc} error={profileErrors.bank_ifsc} onChange={(e) => setProfileForm({ ...profileForm, bank_ifsc: e.target.value })} />
                                <div className="sm:col-span-3"><Button type="submit">Save identifiers</Button></div>
                            </form>
                        )}
                    </>
                )}
            </Card>

            <Card
                title="Exemption claims"
                dense
                actions={<Button size="sm" onClick={() => setDeclModal(true)}>File a claim</Button>}
            >
                {!declarations ? (
                    <div className="flex justify-center py-8"><Spinner /></div>
                ) : declarations.length === 0 ? (
                    <EmptyState title="No claims" />
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>Employee</Th><Th>Year</Th><Th>Section</Th><Th>Amount</Th><Th>Status</Th>{canManage && <Th><span className="sr-only">Actions</span></Th>}</tr>
                        </thead>
                        <tbody>
                            {declarations.map((declaration) => (
                                <tr key={declaration.id}>
                                    <Td>{declaration.employee?.name ?? `#${declaration.employee_id}`}</Td>
                                    <Td>{declaration.fiscal_year}</Td>
                                    <Td>{declaration.section}</Td>
                                    <Td>{declaration.declared_amount}</Td>
                                    <Td>{declaration.status}</Td>
                                    {canManage && (
                                        <Td>
                                            <div className="flex flex-wrap gap-2">
                                                {declaration.status === 'draft' && <Button size="sm" variant="secondary" onClick={() => decideDeclaration(declaration, 'submit')}>Submit</Button>}
                                                {declaration.status === 'submitted' && (
                                                    <>
                                                        <Button size="sm" variant="secondary" onClick={() => decideDeclaration(declaration, 'verify')}>Verify</Button>
                                                        <Button size="sm" variant="danger" onClick={() => decideDeclaration(declaration, 'reject')}>Reject</Button>
                                                    </>
                                                )}
                                            </div>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            {canManage && (
                <Card title="TDS projection" dense>
                    <form onSubmit={runProjection} className="mb-4 grid gap-3 sm:grid-cols-3">
                        <Select label="Employee" value={projEmployee} onChange={(e) => setProjEmployee(e.target.value)} required>
                            <option value="">Select a person…</option>
                            {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                        </Select>
                        <Input label="Fiscal year" value={projYear} onChange={(e) => setProjYear(e.target.value)} required />
                        <div className="flex items-end"><Button type="submit">Project year</Button></div>
                    </form>

                    {projection && (
                        <>
                            {projection.warnings?.length > 0 && (
                                <Alert>
                                    Under-deducted quarters: {projection.warnings.map((w) => `Q${w.quarter} owes ${w.shortfall}`).join(', ')}.
                                </Alert>
                            )}
                            <Table>
                                <thead>
                                    <tr><Th>Quarter</Th><Th>Projected</Th><Th>Liability</Th><Th>Deducted</Th><Th>Surrendered</Th><Th>Shortfall</Th><Th><span className="sr-only">Actions</span></Th></tr>
                                </thead>
                                <tbody>
                                    {projection.projects.map((project) => (
                                        <tr key={project.id}>
                                            <Td>Q{project.quarter}</Td>
                                            <Td>{project.projected_income}</Td>
                                            <Td>{project.tax_liability}</Td>
                                            <Td>{project.tds_deducted}</Td>
                                            <Td>{project.tds_surrendered}{project.challan_ref ? ` (${project.challan_ref})` : ''}</Td>
                                            <Td>{project.shortfall}</Td>
                                            <Td>
                                                {project.shortfall !== '0.00' && (
                                                    <Button size="sm" variant="secondary" onClick={() => setSurrendering(project)}>Surrender</Button>
                                                )}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </>
                    )}
                </Card>
            )}

            {canManage && (
                <Card title="Recompute a run" dense>
                    <form onSubmit={recompute} className="grid gap-3 sm:grid-cols-3">
                        <Input label="Run id" value={recomputeForm.run_id} onChange={(e) => setRecomputeForm({ ...recomputeForm, run_id: e.target.value })} required />
                        <label className="flex items-end gap-2 pb-2 text-sm">
                            <input type="checkbox" checked={recomputeForm.force} onChange={(e) => setRecomputeForm({ ...recomputeForm, force: e.target.checked })} />
                            Full rebuild (not just statutory lines)
                        </label>
                        <div className="flex items-end"><Button type="submit" variant="secondary">Recompute</Button></div>
                    </form>
                    {recomputeResult && (
                        <p className="mt-2 text-sm text-gray-600">
                            {recomputeResult.payslips} payslip{recomputeResult.payslips === 1 ? '' : 's'} {recomputeResult.full ? 'rebuilt fully' : 'refreshed'}.
                        </p>
                    )}
                </Card>
            )}

            <Modal open={configModal} onClose={() => setConfigModal(false)} title={editingConfig ? 'Edit rulebook' : 'New rulebook'} size="lg">
                <form onSubmit={saveConfig} className="grid gap-3 sm:grid-cols-2">
                    {!editingConfig && (
                        <>
                            <Input label="Country (2-letter)" value={configForm.country} error={configErrors.country} onChange={(e) => setConfigForm({ ...configForm, country: e.target.value })} maxLength={2} required />
                            <Input label="Code" value={configForm.code} error={configErrors.code} onChange={(e) => setConfigForm({ ...configForm, code: e.target.value })} required />
                        </>
                    )}
                    <Input label="Name" value={configForm.name} error={configErrors.name} onChange={(e) => setConfigForm({ ...configForm, name: e.target.value })} required />
                    <Input label="Region (empty = country-wide)" value={configForm.region} error={configErrors.region} onChange={(e) => setConfigForm({ ...configForm, region: e.target.value })} />
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={configForm.is_active} onChange={(e) => setConfigForm({ ...configForm, is_active: e.target.checked })} />
                        Active
                    </label>
                    <div className="sm:col-span-2">
                        <label htmlFor="statutory-rules" className="mb-1.5 block text-sm font-medium text-gray-700">Rules JSON (rates, ceilings, slabs)</label>
                        <textarea
                            id="statutory-rules"
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs"
                            rows={10}
                            value={configForm.config_json}
                            onChange={(e) => setConfigForm({ ...configForm, config_json: e.target.value })}
                            placeholder='{"pf": {"enabled": true, ...}}'
                        />
                        {configErrors.config_json && <p className="mt-1.5 text-sm text-red-600">{configErrors.config_json}</p>}
                    </div>
                    <div className="sm:col-span-2"><Button type="submit">{editingConfig ? 'Save rulebook' : 'Create rulebook'}</Button></div>
                </form>
            </Modal>

            <Modal open={declModal} onClose={() => setDeclModal(false)} title="File an exemption claim">
                <form onSubmit={saveDeclaration} className="grid gap-3">
                    <Select label="Employee" value={declForm.employee_id} error={declErrors.employee_id} onChange={(e) => setDeclForm({ ...declForm, employee_id: e.target.value })} required>
                        <option value="">Select a person…</option>
                        {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                    </Select>
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Fiscal year" value={declForm.fiscal_year} error={declErrors.fiscal_year} onChange={(e) => setDeclForm({ ...declForm, fiscal_year: e.target.value })} required />
                        <Input label="Section" value={declForm.section} error={declErrors.section} onChange={(e) => setDeclForm({ ...declForm, section: e.target.value })} placeholder="80c" required />
                    </div>
                    <Input label="Declared amount" value={declForm.declared_amount} error={declErrors.declared_amount} onChange={(e) => setDeclForm({ ...declForm, declared_amount: e.target.value })} required />
                    <div><Button type="submit">File as draft</Button></div>
                </form>
            </Modal>

            <Modal open={!!surrendering} onClose={() => setSurrendering(null)} title={`Surrender Q${surrendering?.quarter ?? ''} shortfall`}>
                <form onSubmit={surrender} className="grid gap-3">
                    <Input label="Challan reference" value={challan} error={challanErrors.challan_ref} onChange={(e) => setChallan(e.target.value)} required />
                    <div><Button type="submit">Record deposit</Button></div>
                </form>
            </Modal>
        </div>
    );
}
