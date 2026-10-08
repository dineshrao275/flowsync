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

const STATUS_OPTIONS = [
    { value: '', label: 'Pending only' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
];

function correctedTime(value) {
    if (!value) return '—';

    return new Date(value).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/**
 * The regularization queue: correction asks awaiting a decision, and the
 * trail of decided ones.
 *
 * The route is gated on `hrms.attendance.regularize`, but deciding still
 * belongs to the approval step's approver — the backend answers 403 for a
 * permission holder who is not on the chain, and the toast says exactly
 * that rather than a generic failure. Rejection requires a reason; the
 * employee sees it on resubmission.
 */
export default function AttendanceApprovals() {
    usePageTitle('Attendance approvals');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [status, setStatus] = useState('');
    const [requests, setRequests] = useState(null);
    const [error, setError] = useState(null);
    const [deciding, setDeciding] = useState(null);
    const [note, setNote] = useState('');
    const [noteErrors, setNoteErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Attendance', to: '/hrms/attendance' }, { label: 'Approvals' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);

        const params = status ? { status } : { status: 'pending' };

        return api
            .get('/hrms/attendance/regularizations', { params })
            .then(({ data }) => setRequests(data.requests ?? []))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError('Unable to load correction requests.');
            });
    }, [status, navigate]);

    useEffect(() => {
        load();
    }, [load]);

    function openDecide(request, verdict) {
        setDeciding({ ...request, verdict });
        setNote('');
        setNoteErrors({});
    }

    async function submitDecide(e) {
        e.preventDefault();
        setNoteErrors({});

        if (!deciding) return;

        setSaving(true);

        try {
            await api.post(`/hrms/attendance/regularizations/${deciding.id}/${deciding.verdict}`, { note: note || null });

            toast.success(deciding.verdict === 'approve' ? 'Correction approved and applied.' : 'Correction rejected.');
            setDeciding(null);
            load();
        } catch (err) {
            if (err.response?.status === 403) {
                toast.error('Only the assigned approver can decide this request.');
                return;
            }

            setNoteErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Attendance approvals</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {requests ? `${requests.length} request${requests.length === 1 ? '' : 's'}` : '—'}
                    </p>
                </div>

                <Select aria-label="Status filter" value={status} onChange={(e) => setStatus(e.target.value)} className="w-44">
                    {STATUS_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense>
                {!requests ? (
                    <div className="flex justify-center py-10">
                        <Spinner />
                    </div>
                ) : requests.length === 0 ? (
                    <EmptyState title="Queue clear" description="No correction requests are waiting for a decision." />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Employee</Th>
                                <Th>Date</Th>
                                <Th>Corrected times</Th>
                                <Th>Reason</Th>
                                <Th>Status</Th>
                                <Th><span className="sr-only">Actions</span></Th>
                            </tr>
                        </thead>
                        <tbody>
                            {requests.map((request) => (
                                <tr key={request.id}>
                                    <Td>{request.employee?.name ?? '—'}</Td>
                                    <Td>{request.work_date}</Td>
                                    <Td>
                                        {correctedTime(request.requested_first_in_at)}
                                        {' → '}
                                        {correctedTime(request.requested_punch_at)}
                                    </Td>
                                    <Td>
                                        <span className="block max-w-xs truncate" title={request.reason}>
                                            {request.reason}
                                        </span>
                                    </Td>
                                    <Td>{request.status_label}</Td>
                                    <Td>
                                        {request.status === 'pending' && (
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => openDecide(request, 'approve')}>
                                                    Approve
                                                </Button>
                                                <Button variant="secondary" onClick={() => openDecide(request, 'reject')}>
                                                    Reject
                                                </Button>
                                            </div>
                                        )}
                                        {request.status !== 'pending' && request.decided_by && (
                                            <span className="text-xs text-gray-500">by {request.decided_by.name}</span>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Modal
                open={!!deciding}
                onClose={() => setDeciding(null)}
                title={deciding?.verdict === 'approve' ? `Approve correction for ${deciding?.work_date}` : `Reject correction for ${deciding?.work_date}`}
            >
                <form onSubmit={submitDecide} className="space-y-3">
                    <p className="text-sm text-gray-500">
                        {deciding?.verdict === 'approve'
                            ? 'Approval inserts the corrected punches and recomputes the day. A short note is optional.'
                            : 'A rejection needs a reason the employee can act on.'}
                    </p>
                    <Input
                        label={deciding?.verdict === 'approve' ? 'Note (optional)' : 'Reason'}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        error={noteErrors.note ?? noteErrors.decision_note}
                        placeholder={deciding?.verdict === 'approve' ? 'Confirmed with the gate register…' : 'The register shows no entry…'}
                    />
                    {noteErrors.form && <Alert>{noteErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setDeciding(null)}>Cancel</Button>
                        <Button type="submit" disabled={saving}>
                            {saving ? 'Saving…' : deciding?.verdict === 'approve' ? 'Approve' : 'Reject'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
