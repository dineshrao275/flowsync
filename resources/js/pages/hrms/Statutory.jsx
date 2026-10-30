import { useCallback, useEffect, useState } from 'react';
import PersonSelect from '../../components/ui/PersonSelect';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { Table, Th, Td } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../../components/ui/ConfirmDialog';
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
    const { t } = useTranslation();
    usePageTitle(t('hrms.statutory.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();

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
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: t('hrms.statutory.pageTitle') }]);
    }, [setCrumbs, t]);

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
                setError(t('hrms.statutory.loadConfigsFailed'));
            });
    }, [navigate, t]);

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
                setError(t('hrms.statutory.loadDeclarationsFailed'));
            });
    }, [navigate, t]);

    useEffect(() => {
        if (canManage) {
            loadConfigs();
            loadDeclarations();
        } else {
            setConfigs([]);
            setDeclarations([]);
        }

        api.get('/hrms/employees', { params: { per_page: 250 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name, email: e.user?.email }))))
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
                    fail(t('hrms.statutory.loadProfileFailed'))(err);
                });
        },
        [fail, t],
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
                setConfigErrors({ config_json: t('hrms.statutory.invalidJson') });
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
                toast.success(t('hrms.statutory.rulebookUpdated'));
            } else {
                await api.post('/hrms/payroll/statutory/configurations', payload);
                toast.success(t('hrms.statutory.rulebookCreated'));
            }

            setConfigModal(false);
            loadConfigs();
        } catch (err) {
            setConfigErrors(fieldErrors(err));
        }
    }

    async function deleteConfig(config) {
        if (!(await confirm({ title: t('hrms.statutory.deleteConfigTitle'), message: t('hrms.statutory.deleteConfigMessage', { name: config.name }), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/payroll/statutory/configurations/${config.id}`);
            toast.success(t('hrms.statutory.rulebookDeleted'));
            loadConfigs();
        } catch {
            setError(t('hrms.statutory.rulebookDeleteFailed'));
        }
    }

    async function saveProfile(e) {
        e.preventDefault();
        setProfileErrors({});

        try {
            const { data } = await api.put(`/hrms/payroll/statutory/profiles/${employeeId}`, profileForm);
            toast.success(t('hrms.statutory.profileSaved'));
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
            toast.success(t('hrms.statutory.revealShown'));
        } catch {
            setError(t('hrms.statutory.revealFailed'));
        }
    }

    async function saveDeclaration(e) {
        e.preventDefault();
        setDeclErrors({});

        try {
            await api.post('/hrms/payroll/statutory/declarations', declForm);
            toast.success(t('hrms.statutory.claimFiled'));
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
            toast.success(verdict === 'verify' ? t('hrms.statutory.claimVerified') : verdict === 'reject' ? t('hrms.statutory.claimRejected') : t('hrms.statutory.claimSubmitted'));
            loadDeclarations();
        } catch {
            setError(t('hrms.statutory.transitionRefused'));
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
            setError(t('hrms.statutory.projectionFailed'));
        }
    }

    async function surrender(e) {
        e.preventDefault();
        setChallanErrors({});

        try {
            await api.post(`/hrms/payroll/statutory/tds-projects/${surrendering.id}/surrender`, { challan_ref: challan });
            toast.success(t('hrms.statutory.shortfallSurrendered'));
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
            toast.success(t('hrms.statutory.recomputeDone'));
        } catch {
            setError(t('hrms.statutory.recomputeFailed'));
        }
    }

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.statutory.pageTitle')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">{t('hrms.statutory.intro')}</p>
            </div>

            {error && <Alert>{error}</Alert>}

            {canManage && (
                <Card
                    title={t('hrms.statutory.rulebooks')}
                    dense
                    actions={<Button size="sm" onClick={() => openConfigModal(null)}>{t('hrms.statutory.newRulebook')}</Button>}
                >
                    {!configs ? (
                        <ContentSkeleton size="md" />
                    ) : configs.length === 0 ? (
                        <EmptyState title={t('hrms.statutory.noRulebooks')} hint={t('hrms.statutory.noRulebooksHint')} />
                    ) : (
                        <Table>
                            <thead>
                                <tr><Th>{t('hrms.statutory.code')}</Th><Th>{t('hrms.statutory.country')}</Th><Th>{t('hrms.statutory.region')}</Th><Th>{t('hrms.statutory.active')}</Th><Th><span className="sr-only">{t('hrms.common.actions')}</span></Th></tr>
                            </thead>
                            <tbody>
                                {configs.map((config) => (
                                    <tr key={config.id}>
                                        <Td>{config.code}</Td>
                                        <Td>{config.country}</Td>
                                        <Td>{config.region ?? '—'}</Td>
                                        <Td>{config.is_active ? t('hrms.common.yes') : t('hrms.common.no')}</Td>
                                        <Td>
                                            <div className="flex gap-2">
                                                <Button size="sm" variant="secondary" onClick={() => openConfigModal(config)}>{t('hrms.common.edit')}</Button>
                                                <Button size="sm" variant="danger" onClick={() => deleteConfig(config)}>{t('hrms.common.delete')}</Button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}

            <Card title={t('hrms.statutory.identifiers')} dense>
                <div className="mb-4 max-w-sm">
                    <PersonSelect label={t('hrms.statutory.employee')} value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} placeholder={t('hrms.statutory.selectPerson')} people={employees} />
                </div>

                {employeeId && (
                    <>
                        {!profileLoaded ? (
                            <ContentSkeleton size="md" />
                        ) : !profile ? (
                            <p className="text-sm text-gray-500">{t('hrms.statutory.noIdentifiers')}</p>
                        ) : (
                            <div className="mb-4 rounded-lg border border-gray-200 p-4 text-sm">
                                <div className="grid gap-1 sm:grid-cols-2">
                                    <span>{t('hrms.statutory.pan')} <strong>{profile.pan ?? '—'}</strong></span>
                                    <span>{t('hrms.statutory.aadhaar')} <strong>{profile.aadhaar_last4 ?? '—'}</strong></span>
                                    <span>{t('hrms.statutory.uan')} <strong>{profile.uan ?? '—'}</strong></span>
                                    <span>{t('hrms.statutory.account')} <strong>{profile.bank_account ?? '—'}</strong></span>
                                </div>
                                {canManage && (
                                    <div className="mt-3">
                                        <Button size="sm" variant="secondary" onClick={revealProfile}>{t('hrms.statutory.revealCleartext')}</Button>
                                    </div>
                                )}
                                {revealed && (
                                    <div className="mt-3 rounded-lg bg-amber-50 p-3 text-sm">
                                        <p className="font-medium text-amber-900">{t('hrms.statutory.cleartextHeading')}</p>
                                        <p className="mt-1">{t('hrms.statutory.pan')} {revealed.pan ?? '—'} · {t('hrms.statutory.uan')} {revealed.uan ?? '—'} · {t('hrms.statutory.account')} {revealed.bank_account ?? '—'}</p>
                                    </div>
                                )}
                            </div>
                        )}

                        {canManage && (
                            <form onSubmit={saveProfile} className="grid gap-3 sm:grid-cols-3">
                                <Input label={t('hrms.statutory.panLabel')} value={profileForm.pan} error={profileErrors.pan} onChange={(e) => setProfileForm({ ...profileForm, pan: e.target.value })} />
                                <Input label={t('hrms.statutory.aadhaarField')} value={profileForm.aadhaar} error={profileErrors.aadhaar} onChange={(e) => setProfileForm({ ...profileForm, aadhaar: e.target.value })} />
                                <Input label={t('hrms.statutory.uanLabel')} value={profileForm.uan} error={profileErrors.uan} onChange={(e) => setProfileForm({ ...profileForm, uan: e.target.value })} />
                                <Input label={t('hrms.statutory.esiNumber')} value={profileForm.esi_number} error={profileErrors.esi_number} onChange={(e) => setProfileForm({ ...profileForm, esi_number: e.target.value })} />
                                <Input label={t('hrms.statutory.pfNumber')} value={profileForm.pf_number} error={profileErrors.pf_number} onChange={(e) => setProfileForm({ ...profileForm, pf_number: e.target.value })} />
                                <Input label={t('hrms.statutory.ptState')} value={profileForm.pt_state} error={profileErrors.pt_state} onChange={(e) => setProfileForm({ ...profileForm, pt_state: e.target.value })} />
                                <Input label={t('hrms.statutory.bankName')} value={profileForm.bank_name} error={profileErrors.bank_name} onChange={(e) => setProfileForm({ ...profileForm, bank_name: e.target.value })} />
                                <Input label={t('hrms.statutory.bankAccount')} value={profileForm.bank_account} error={profileErrors.bank_account} onChange={(e) => setProfileForm({ ...profileForm, bank_account: e.target.value })} />
                                <Input label={t('hrms.statutory.bankIfsc')} value={profileForm.bank_ifsc} error={profileErrors.bank_ifsc} onChange={(e) => setProfileForm({ ...profileForm, bank_ifsc: e.target.value })} />
                                <div className="sm:col-span-3"><Button type="submit">{t('hrms.statutory.saveIdentifiers')}</Button></div>
                            </form>
                        )}
                    </>
                )}
            </Card>

            <Card
                title={t('hrms.statutory.claims')}
                dense
                actions={<Button size="sm" onClick={() => setDeclModal(true)}>{t('hrms.statutory.fileClaim')}</Button>}
            >
                {!declarations ? (
                    <ContentSkeleton size="md" />
                ) : declarations.length === 0 ? (
                    <EmptyState title={t('hrms.statutory.noClaims')} />
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>{t('hrms.statutory.employee')}</Th><Th>{t('hrms.statutory.year')}</Th><Th>{t('hrms.statutory.section')}</Th><Th>{t('hrms.statutory.amount')}</Th><Th>{t('hrms.statutory.status')}</Th>{canManage && <Th><span className="sr-only">{t('hrms.common.actions')}</span></Th>}</tr>
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
                                                {declaration.status === 'draft' && <Button size="sm" variant="secondary" onClick={() => decideDeclaration(declaration, 'submit')}>{t('hrms.common.submit')}</Button>}
                                                {declaration.status === 'submitted' && (
                                                    <>
                                                        <Button size="sm" variant="secondary" onClick={() => decideDeclaration(declaration, 'verify')}>{t('hrms.statutory.verify')}</Button>
                                                        <Button size="sm" variant="danger" onClick={() => decideDeclaration(declaration, 'reject')}>{t('hrms.statutory.reject')}</Button>
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
                <Card title={t('hrms.statutory.tdsProjection')} dense>
                    <form onSubmit={runProjection} className="mb-4 grid gap-3 sm:grid-cols-3">
                        <PersonSelect label={t('hrms.statutory.employee')} value={projEmployee} onChange={(e) => setProjEmployee(e.target.value)} required placeholder={t('hrms.statutory.selectPerson')} people={employees} />
                        <Input label={t('hrms.statutory.fiscalYear')} value={projYear} onChange={(e) => setProjYear(e.target.value)} required />
                        <div className="flex items-end"><Button type="submit">{t('hrms.statutory.projectYear')}</Button></div>
                    </form>

                    {projection && (
                        <>
                            {projection.warnings?.length > 0 && (
                                <Alert>
                                    {t('hrms.statutory.underDeducted')} {projection.warnings.map((w) => t('hrms.statutory.owes', { quarter: w.quarter, shortfall: w.shortfall })).join(', ')}.
                                </Alert>
                            )}
                            <Table>
                                <thead>
                                    <tr><Th>{t('hrms.statutory.quarter')}</Th><Th>{t('hrms.statutory.projected')}</Th><Th>{t('hrms.statutory.liability')}</Th><Th>{t('hrms.statutory.deducted')}</Th><Th>{t('hrms.statutory.surrendered')}</Th><Th>{t('hrms.statutory.shortfall')}</Th><Th><span className="sr-only">{t('hrms.common.actions')}</span></Th></tr>
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
                                                    <Button size="sm" variant="secondary" onClick={() => setSurrendering(project)}>{t('hrms.statutory.surrender')}</Button>
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
                <Card title={t('hrms.statutory.recomputeRun')} dense>
                    <form onSubmit={recompute} className="grid gap-3 sm:grid-cols-3">
                        <Input label={t('hrms.statutory.runId')} value={recomputeForm.run_id} onChange={(e) => setRecomputeForm({ ...recomputeForm, run_id: e.target.value })} required />
                        <label className="flex items-end gap-2 pb-2 text-sm">
                            <input type="checkbox" checked={recomputeForm.force} onChange={(e) => setRecomputeForm({ ...recomputeForm, force: e.target.checked })} />
                            {t('hrms.statutory.fullRebuild')}
                        </label>
                        <div className="flex items-end"><Button type="submit" variant="secondary">{t('hrms.statutory.recompute')}</Button></div>
                    </form>
                    {recomputeResult && (
                        <p className="mt-2 text-sm text-gray-600">
                            {recomputeResult.payslips === 1
                                ? t('hrms.statutory.recomputeSummaryOne', { mode: recomputeResult.full ? t('hrms.statutory.rebuiltFully') : t('hrms.statutory.refreshed') })
                                : t('hrms.statutory.recomputeSummary', { count: recomputeResult.payslips, mode: recomputeResult.full ? t('hrms.statutory.rebuiltFully') : t('hrms.statutory.refreshed') })}
                        </p>
                    )}
                </Card>
            )}

            <Modal open={configModal} onClose={() => setConfigModal(false)} title={editingConfig ? t('hrms.statutory.configModalEdit') : t('hrms.statutory.configModalNew')} size="lg">
                <form onSubmit={saveConfig} className="grid gap-3 px-6 py-5 sm:grid-cols-2">
                    {!editingConfig && (
                        <>
                            <Input label={t('hrms.statutory.country2')} value={configForm.country} error={configErrors.country} onChange={(e) => setConfigForm({ ...configForm, country: e.target.value })} maxLength={2} required />
                            <Input label={t('hrms.statutory.code')} value={configForm.code} error={configErrors.code} onChange={(e) => setConfigForm({ ...configForm, code: e.target.value })} required />
                        </>
                    )}
                    <Input label={t('hrms.statutory.name')} value={configForm.name} error={configErrors.name} onChange={(e) => setConfigForm({ ...configForm, name: e.target.value })} required />
                    <Input label={t('hrms.statutory.regionLabel')} value={configForm.region} error={configErrors.region} onChange={(e) => setConfigForm({ ...configForm, region: e.target.value })} />
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={configForm.is_active} onChange={(e) => setConfigForm({ ...configForm, is_active: e.target.checked })} />
                        {t('hrms.statutory.active')}
                    </label>
                    <div className="sm:col-span-2">
                        <label htmlFor="statutory-rules" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.statutory.rulesJson')}</label>
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
                    <div className="sm:col-span-2"><Button type="submit">{editingConfig ? t('hrms.statutory.saveRulebook') : t('hrms.statutory.createRulebook')}</Button></div>
                </form>
            </Modal>

            <Modal open={declModal} onClose={() => setDeclModal(false)} title={t('hrms.statutory.declModalTitle')}>
                <form onSubmit={saveDeclaration} className="grid gap-3 px-6 py-5">
                    <PersonSelect label={t('hrms.statutory.employee')} value={declForm.employee_id} error={declErrors.employee_id} onChange={(e) => setDeclForm({ ...declForm, employee_id: e.target.value })} required placeholder={t('hrms.statutory.selectPerson')} people={employees} />
                    <div className="grid grid-cols-2 gap-3">
                        <Input label={t('hrms.statutory.fiscalYear')} value={declForm.fiscal_year} error={declErrors.fiscal_year} onChange={(e) => setDeclForm({ ...declForm, fiscal_year: e.target.value })} required />
                        <Input label={t('hrms.statutory.section')} value={declForm.section} error={declErrors.section} onChange={(e) => setDeclForm({ ...declForm, section: e.target.value })} placeholder="80c" required />
                    </div>
                    <Input label={t('hrms.statutory.declaredAmount')} value={declForm.declared_amount} error={declErrors.declared_amount} onChange={(e) => setDeclForm({ ...declForm, declared_amount: e.target.value })} required />
                    <div><Button type="submit">{t('hrms.statutory.fileAsDraft')}</Button></div>
                </form>
            </Modal>

            <Modal open={!!surrendering} onClose={() => setSurrendering(null)} title={t('hrms.statutory.surrenderTitle', { quarter: surrendering?.quarter ?? '' })}>
                <form onSubmit={surrender} className="grid gap-3 px-6 py-5">
                    <Input label={t('hrms.statutory.challanReference')} value={challan} error={challanErrors.challan_ref} onChange={(e) => setChallan(e.target.value)} required />
                    <div><Button type="submit">{t('hrms.statutory.recordDeposit')}</Button></div>
                </form>
            </Modal>
        </div>
    );
}
