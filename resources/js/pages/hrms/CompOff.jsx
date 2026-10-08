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
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { formatMinutes } from '../../utils/time';

const SOURCES = [
    { value: 'manual', label: 'Manual grant' },
    { value: 'special', label: 'Special day' },
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
    usePageTitle('Comp-off');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

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
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Comp-off' }]);
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

    const loadBank = useCallback(() => {
        if (!employeeId) {
            setBank(null);

            return Promise.resolve();
        }

        return api
            .get('/hrms/comp-off/credits', { params: { employee_id: Number(employeeId) } })
            .then(({ data }) => setBank(data))
            .catch(fail('Unable to load the bank.'));
    }, [employeeId, fail]);

    const loadRequests = useCallback(() => {
        const params = requestStatus ? { status: requestStatus } : {};

        return api
            .get('/hrms/comp-off/requests', { params })
            .then(({ data }) => setRequests(data.requests ?? []))
            .catch(fail('Unable to load comp-off requests.'));
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

            toast.success(verdict === 'approve' ? 'Comp-off approved.' : 'Comp-off rejected.');
            setDeciding(null);
            setDecisionNote('');
            loadRequests();
        } catch (err) {
            if (err.response?.status === 403) {
                toast.error('Only the assigned approver can decide this request.');
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

            toast.success('Comp-off granted.');
            setGranting(false);
            setGrant({ employee_id: '', work_date: '', minutes: '', source: 'manual', note: '' });
            reload();
        } catch (err) {
            setGrantErrors(fieldErrors(err));
        }
    }

    async function runAccrual() {
        const { from, to } = previousMonth();

        if (!window.confirm(`Credit comp-off for ${from} → ${to}? Reruns credit nothing new.`)) return;

        setAccruing(true);

        try {
            const { data } = await api.post('/hrms/comp-off/accrue', { from, to });

            toast.success(`Accrual finished: ${data.credited} credited, ${data.skipped} skipped.`);
            reload();
        } catch (err) {
            toast.error('Unable to run the accrual.');
        } finally {
            setAccruing(false);
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Comp-off</h2>
                    <p className="mt-0.5 text-sm text-gray-500">Banks, redemptions, grants, accrual runs.</p>
                </div>

                <div className="flex gap-2">
                    <Button variant="secondary" onClick={() => setGranting(true)}>Grant time</Button>
                    <Button variant="secondary" onClick={runAccrual} disabled={accruing}>
                        {accruing ? 'Accruing…' : 'Run accrual'}
                    </Button>
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense title="Bank">
                <div className="mb-3">
                    <Select aria-label="Employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} className="w-56">
                        <option value="">Select an employee…</option>
                        {employees.map((employee) => (
                            <option key={employee.id} value={employee.id}>
                                {employee.name}
                            </option>
                        ))}
                    </Select>
                </div>

                {!employeeId ? (
                    <EmptyState title="No employee selected" description="Pick an employee to see their bank." />
                ) : !bank ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : (
                    <>
                        <dl className="mb-3 grid grid-cols-3 gap-2 text-sm">
                            <div><dt className="text-gray-500">Free</dt><dd className="text-lg font-semibold text-gray-900">{formatMinutes(bank.balance_minutes)}</dd></div>
                            <div><dt className="text-gray-500">Expiring soon</dt><dd className="text-lg font-semibold text-gray-900">{formatMinutes(bank.expiring_minutes)}</dd></div>
                            <div><dt className="text-gray-500">Expired</dt><dd className="text-lg font-semibold text-gray-400">{formatMinutes(bank.expired_minutes)}</dd></div>
                        </dl>
                        {bank.credits.length === 0 ? (
                            <EmptyState title="Nothing banked" description="No credits on record." />
                        ) : (
                            <Table>
                                <thead>
                                    <tr>
                                        <Th>Date</Th>
                                        <Th>Source</Th>
                                        <Th>Minutes</Th>
                                        <Th>Expires</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {bank.credits.map((credit) => (
                                        <tr key={credit.id}>
                                            <Td>{credit.work_date}</Td>
                                            <Td>{credit.source_label}</Td>
                                            <Td>{formatMinutes(credit.minutes)}</Td>
                                            <Td>{credit.expiry_date ?? 'Never'}</Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </>
                )}
            </Card>

            <Card dense title="Redemptions">
                <div className="mb-3 flex items-center gap-2">
                    <Select aria-label="Status filter" value={requestStatus} onChange={(e) => setRequestStatus(e.target.value)} className="w-44">
                        <option value="">All open</option>
                        <option value="submitted">Submitted</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </Select>
                </div>

                {!requests ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : requests.length === 0 ? (
                    <EmptyState title="No requests" description="Nothing filed under this filter." />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Employee</Th>
                                <Th>Dates</Th>
                                <Th>Minutes</Th>
                                <Th>Status</Th>
                                <Th><span className="sr-only">Actions</span></Th>
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
                                                    Approve
                                                </Button>
                                                <Button variant="secondary" onClick={() => { setDeciding({ ...request, verdict: 'reject' }); setDecisionNote(''); setDecisionErrors({}); }}>
                                                    Reject
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

            <Modal open={!!deciding} onClose={() => setDeciding(null)} title={deciding?.verdict === 'approve' ? 'Approve comp-off' : 'Reject comp-off'}>
                <div className="space-y-3">
                    <p className="text-sm text-gray-500">
                        {deciding?.verdict === 'approve'
                            ? 'Approval spends the banked minutes immediately.'
                            : 'A rejection needs a reason the requester can act on.'}
                    </p>
                    <Input label={deciding?.verdict === 'approve' ? 'Note (optional)' : 'Reason'} value={decisionNote} onChange={(e) => setDecisionNote(e.target.value)} error={decisionErrors.note ?? decisionErrors.decision_note} />
                    {decisionErrors.form && <Alert>{decisionErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeciding(null)}>Cancel</Button>
                        <Button onClick={() => decide(deciding.verdict)}>
                            {deciding?.verdict === 'approve' ? 'Approve' : 'Reject'}
                        </Button>
                    </div>
                </div>
            </Modal>

            <Modal open={granting} onClose={() => setGranting(false)} title="Grant comp-off">
                <form onSubmit={submitGrant} className="space-y-3">
                    <Select label="Employee" value={grant.employee_id} onChange={(e) => setGrant({ ...grant, employee_id: e.target.value })} error={grantErrors.employee_id}>
                        <option value="">Select…</option>
                        {employees.map((employee) => (
                            <option key={employee.id} value={employee.id}>
                                {employee.name}
                            </option>
                        ))}
                    </Select>
                    <div className="grid grid-cols-2 gap-3">
                        <Input type="date" label="Work date" value={grant.work_date} onChange={(e) => setGrant({ ...grant, work_date: e.target.value })} error={grantErrors.work_date} />
                        <Input type="number" label="Minutes" value={grant.minutes} onChange={(e) => setGrant({ ...grant, minutes: e.target.value })} error={grantErrors.minutes} />
                    </div>
                    <Select label="Source" value={grant.source} onChange={(e) => setGrant({ ...grant, source: e.target.value })} error={grantErrors.source}>
                        {SOURCES.map((option) => (
                            <option key={option.value} value={option.value}>{option.label}</option>
                        ))}
                    </Select>
                    <Input label="Note (optional)" value={grant.note} onChange={(e) => setGrant({ ...grant, note: e.target.value })} error={grantErrors.note} />
                    {grantErrors.form && <Alert>{grantErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setGranting(false)}>Cancel</Button>
                        <Button type="submit">Grant</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
