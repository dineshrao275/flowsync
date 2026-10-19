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
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { formatMinutes } from '../../utils/time';

const SOURCES = [
    { value: 'manual', key: 'srcManual' },
    { value: 'special', key: 'srcSpecial' },
];

function previousMonth() {
    const date = new Date();
    const first = new Date(date.getFullYear(), date.getMonth() - 1, 1);
    const last = new Date(date.getFullYear(), date.getMonth(), 0);

    const iso = (d) => d.toISOString().slice(0, 10);

    return { from: iso(first), to: iso(last) };
}

/**
 * Comp-off administration: everyone's banks, the redemption queue, manual
 * grants, and the accrual run.
 *
 * Route-gated on `hrms.comp_off.manage`, so every control here may assume
 * it: the bank viewer reads anyone's credits, the queue decides in place,
 * grants name their target, and the run credits a whole window. The
 * self-service twin lives on `/hrms/comp-off/mine`.
 */
export default function CompOff() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.comp.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const confirm = useConfirm();

    const [employees, setEmployees] = useState([]);
    const [employeeId, setEmployeeId] = useState('');
    const [bank, setBank] = useState(null);
    const [requests, setRequests] = useState(null);
    const [requestStatus, setRequestStatus] = useState('');
    const [error, setError] = useState(null);
    const [deciding, setDeciding] = useState(null);
    const [decisionNote, setDecisionNote] = useState('');
    const [decisionErrors, setDecisionErrors] = useState({});
    const [granting, setGranting] = useState(false);
    const [grant, setGrant] = useState({ employee_id: '', work_date: '', minutes: '', source: 'manual', note: '' });
    const [grantErrors, setGrantErrors] = useState({});
    const [accruing, setAccruing] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.comp.pageTitle') }]);
    }, [setCrumbs, t]);

    const fail = useCallback(
        (key) => (err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }

            setError(t(key));
        },
        [navigate, t],
    );

    const loadBank = useCallback(() => {
        if (!employeeId) {
            setBank(null);

            return Promise.resolve();
        }

        return api
            .get('/hrms/comp-off/credits', { params: { employee_id: Number(employeeId) } })
            .then(({ data }) => setBank(data))
            .catch(fail('hrms.comp.bankFailed'));
    }, [employeeId, fail]);

    const loadRequests = useCallback(() => {
        const params = requestStatus ? { status: requestStatus } : {};

        return api
            .get('/hrms/comp-off/requests', { params })
            .then(({ data }) => setRequests(data.requests ?? []))
            .catch(fail('hrms.comp.requestsFailed'));
    }, [requestStatus, fail]);

    useEffect(() => {
        loadRequests();

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        loadBank();
    }, [loadBank]);

    const reload = useCallback(() => {
        loadBank();
        loadRequests();
    }, [loadBank, loadRequests]);

    async function decide(verdict) {
        if (!deciding) return;

        setDecisionErrors({});

        try {
            await api.post(`/hrms/comp-off/requests/${deciding.id}/${verdict}`, { note: decisionNote || null });

            toast.success(verdict === 'approve' ? t('hrms.comp.approved') : t('hrms.comp.rejected'));
            setDeciding(null);
            setDecisionNote('');
            loadRequests();
        } catch (err) {
            if (err.response?.status === 403) {
                toast.error(t('hrms.comp.forbidden'));
                return;
            }

            setDecisionErrors(fieldErrors(err));
        }
    }

    async function submitGrant(e) {
        e.preventDefault();
        setGrantErrors({});

        try {
            await api.post('/hrms/comp-off/credits', {
                employee_id: Number(grant.employee_id),
                work_date: grant.work_date,
                minutes: Number(grant.minutes),
                source: grant.source,
                ...(grant.note ? { note: grant.note } : {}),
            });

            toast.success(t('hrms.comp.granted'));
            setGranting(false);
            setGrant({ employee_id: '', work_date: '', minutes: '', source: 'manual', note: '' });
            reload();
        } catch (err) {
            setGrantErrors(fieldErrors(err));
        }
    }

    async function runAccrual() {
        const { from, to } = previousMonth();

        if (!(await confirm({ title: t('hrms.comp.creditTitle'), message: t('hrms.comp.creditMessage', { from, to }) }))) return;

        setAccruing(true);

        try {
            const { data } = await api.post('/hrms/comp-off/accrue', { from, to });

            toast.success(t('hrms.comp.accrued', { credited: data.credited, skipped: data.skipped }));
            reload();
        } catch (err) {
            toast.error(t('hrms.comp.accrueFailed'));
        } finally {
            setAccruing(false);
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.comp.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">{t('hrms.comp.subtitle')}</p>
                </div>

                <div className="flex gap-2">
                    <Button variant="secondary" onClick={() => setGranting(true)}>{t('hrms.comp.grantTime')}</Button>
                    <Button variant="secondary" onClick={runAccrual} disabled={accruing}>
                        {accruing ? t('hrms.comp.accruing') : t('hrms.comp.runAccrual')}
                    </Button>
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense title={t('hrms.comp.bank')}>
                <div className="mb-3">
                    <PersonSelect aria-label={t('hrms.comp.employee')} value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} className="w-56" placeholder={t('hrms.comp.selectEmployee')} people={employees} />
                </div>

                {!employeeId ? (
                    <EmptyState title={t('hrms.comp.noEmployee')} description={t('hrms.comp.noEmployeeHint')} />
                ) : !bank ? (
                    <ContentSkeleton size="sm" />
                ) : (
                    <>
                        <dl className="mb-3 grid grid-cols-3 gap-2 text-sm">
                            <div><dt className="text-gray-500">{t('hrms.comp.free')}</dt><dd className="text-lg font-semibold text-gray-900">{formatMinutes(bank.balance_minutes)}</dd></div>
                            <div><dt className="text-gray-500">{t('hrms.comp.expiring')}</dt><dd className="text-lg font-semibold text-gray-900">{formatMinutes(bank.expiring_minutes)}</dd></div>
                            <div><dt className="text-gray-500">{t('hrms.comp.expired')}</dt><dd className="text-lg font-semibold text-gray-400">{formatMinutes(bank.expired_minutes)}</dd></div>
                        </dl>
                        {bank.credits.length === 0 ? (
                            <EmptyState title={t('hrms.comp.emptyBank')} description={t('hrms.comp.emptyBankHint')} />
                        ) : (
                            <Table>
                                <thead>
                                    <tr>
                                        <Th>{t('hrms.comp.thDate')}</Th>
                                        <Th>{t('hrms.comp.thSource')}</Th>
                                        <Th>{t('hrms.comp.thMinutes')}</Th>
                                        <Th>{t('hrms.comp.thExpires')}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {bank.credits.map((credit) => (
                                        <tr key={credit.id}>
                                            <Td>{credit.work_date}</Td>
                                            <Td>{credit.source_label}</Td>
                                            <Td>{formatMinutes(credit.minutes)}</Td>
                                            <Td>{credit.expiry_date ?? t('hrms.comp.never')}</Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </>
                )}
            </Card>

            <Card dense title={t('hrms.comp.redemptions')}>
                <div className="mb-3 flex items-center gap-2">
                    <Select aria-label={t('hrms.comp.statusFilter')} value={requestStatus} onChange={(e) => setRequestStatus(e.target.value)} className="w-44">
                        <option value="">{t('hrms.leave.stAllOpen')}</option>
                        <option value="submitted">{t('hrms.leave.stSubmitted')}</option>
                        <option value="pending">{t('hrms.leave.stPending')}</option>
                        <option value="approved">{t('hrms.leave.stApproved')}</option>
                        <option value="rejected">{t('hrms.leave.stRejected')}</option>
                        <option value="cancelled">{t('hrms.leave.stCancelled')}</option>
                    </Select>
                </div>

                {!requests ? (
                    <ContentSkeleton size="sm" />
                ) : requests.length === 0 ? (
                    <EmptyState title={t('hrms.comp.emptyTitle')} description={t('hrms.comp.emptyHint')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.comp.thEmployee')}</Th>
                                <Th>{t('hrms.comp.thDates')}</Th>
                                <Th>{t('hrms.comp.thMinutes')}</Th>
                                <Th>{t('hrms.comp.thStatus')}</Th>
                                <Th><span className="sr-only">{t('hrms.comp.thActions')}</span></Th>
                            </tr>
                        </thead>
                        <tbody>
                            {requests.map((request) => (
                                <tr key={request.id}>
                                    <Td>{request.employee?.name ?? '—'}</Td>
                                    <Td>{request.from_date} → {request.to_date}</Td>
                                    <Td>{formatMinutes(request.total_minutes)}</Td>
                                    <Td>{request.status_label}</Td>
                                    <Td>
                                        {(request.status === 'submitted' || request.status === 'pending') && (
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => { setDeciding({ ...request, verdict: 'approve' }); setDecisionNote(''); setDecisionErrors({}); }}>
                                                    {t('hrms.comp.approve')}
                                                </Button>
                                                <Button variant="secondary" onClick={() => { setDeciding({ ...request, verdict: 'reject' }); setDecisionNote(''); setDecisionErrors({}); }}>
                                                    {t('hrms.comp.reject')}
                                                </Button>
                                            </div>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Modal open={!!deciding} onClose={() => setDeciding(null)} title={deciding?.verdict === 'approve' ? t('hrms.comp.decideApproveTitle') : t('hrms.comp.decideRejectTitle')}>
                <div className="space-y-3 px-6 py-5">
                    <p className="text-sm text-gray-500">
                        {deciding?.verdict === 'approve'
                            ? t('hrms.comp.decideApproveIntro')
                            : t('hrms.comp.decideRejectIntro')}
                    </p>
                    <Input label={deciding?.verdict === 'approve' ? t('hrms.comp.noteOptional') : t('hrms.comp.reason')} value={decisionNote} onChange={(e) => setDecisionNote(e.target.value)} error={decisionErrors.note ?? decisionErrors.decision_note} />
                    {decisionErrors.form && <Alert>{decisionErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeciding(null)}>{t('hrms.comp.cancel')}</Button>
                        <Button onClick={() => decide(deciding.verdict)}>
                            {deciding?.verdict === 'approve' ? t('hrms.comp.approve') : t('hrms.comp.reject')}
                        </Button>
                    </div>
                </div>
            </Modal>

            <Modal open={granting} onClose={() => setGranting(false)} title={t('hrms.comp.grantTitle')}>
                <form onSubmit={submitGrant} className="space-y-3 px-6 py-5">
                    <PersonSelect label={t('hrms.comp.employee')} value={grant.employee_id} onChange={(e) => setGrant({ ...grant, employee_id: e.target.value })} error={grantErrors.employee_id} placeholder={t('hrms.comp.select')} people={employees} />
                    <div className="grid grid-cols-2 gap-3">
                        <Input type="date" label={t('hrms.comp.workDate')} value={grant.work_date} onChange={(e) => setGrant({ ...grant, work_date: e.target.value })} error={grantErrors.work_date} />
                        <Input type="number" label={t('hrms.comp.minutes')} value={grant.minutes} onChange={(e) => setGrant({ ...grant, minutes: e.target.value })} error={grantErrors.minutes} />
                    </div>
                    <Select label={t('hrms.comp.source')} value={grant.source} onChange={(e) => setGrant({ ...grant, source: e.target.value })} error={grantErrors.source}>
                        {SOURCES.map((option) => (
                            <option key={option.value} value={option.value}>{t(`hrms.comp.${option.key}`)}</option>
                        ))}
                    </Select>
                    <Input label={t('hrms.comp.noteOptional')} value={grant.note} onChange={(e) => setGrant({ ...grant, note: e.target.value })} error={grantErrors.note} />
                    {grantErrors.form && <Alert>{grantErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setGranting(false)}>{t('hrms.comp.cancel')}</Button>
                        <Button type="submit">{t('hrms.comp.grant')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
