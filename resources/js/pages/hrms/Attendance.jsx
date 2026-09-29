import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { formatMinutes } from '../../utils/time';
import AttendanceCalendar from '../../components/hrms/AttendanceCalendar';
import ClockInWidget from '../../components/hrms/ClockInWidget';

const STATUS_OPTIONS = [
    { value: '', label: 'All statuses' },
    { value: 'present', label: 'Present' },
    { value: 'absent', label: 'Absent' },
    { value: 'half_day', label: 'Half day' },
    { value: 'late', label: 'Late' },
    { value: 'leave', label: 'Leave' },
    { value: 'holiday', label: 'Holiday' },
    { value: 'week_off', label: 'Week off' },
];

function monthLabel(year, month) {
    return new Date(year, month - 1, 1).toLocaleDateString([], { month: 'long', year: 'numeric' });
}

function shiftMonth(year, month, delta) {
    const date = new Date(year, month - 1 + delta, 1);

    return { year: date.getFullYear(), month: date.getMonth() + 1 };
}

/**
 * The attendance workspace: month grid, clock widget, corrections, export.
 *
 * Self-service by default — without `hrms.attendance.view` the page shows
 * the caller's own curve and no employee picker; with it, the picker lists
 * the directory. The month and the widget refetch together after every
 * punch or correction, so the grid never lags the day it photographs.
 */
export default function Attendance() {
    usePageTitle('Attendance');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canViewOthers = can('permission:hrms.attendance.view');
    const canRegularize = can('permission:hrms.attendance.regularize');

    const now = new Date();
    const [employeeId, setEmployeeId] = useState('');
    const [year, setYear] = useState(now.getFullYear());
    const [month, setMonth] = useState(now.getMonth() + 1);
    const [statusFilter, setStatusFilter] = useState('');
    const [monthData, setMonthData] = useState(null);
    const [todayData, setTodayData] = useState(null);
    const [employees, setEmployees] = useState(null);
    const [selected, setSelected] = useState(null);
    const [error, setError] = useState(null);
    const [punching, setPunching] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [correcting, setCorrecting] = useState(false);
    const [correction, setCorrection] = useState({ firstIn: '', lastOut: '', reason: '' });
    const [correctionErrors, setCorrectionErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Attendance' }]);
    }, [setCrumbs]);

    const params = useMemo(
        () => ({ ...(employeeId ? { employee_id: employeeId } : {}), year, month }),
        [employeeId, year, month],
    );

    const load = useCallback(() => {
        setError(null);

        const monthParams = { ...params };

        return Promise.all([
            api.get('/hrms/attendance/month', { params: monthParams }).then(({ data }) => {
                setMonthData(data);
                setSelected((current) => {
                    if (!current) return null;

                    return (data.days ?? []).find((day) => day.date === current.date) ?? null;
                });
            }),
            api.get('/hrms/attendance/today', { params: employeeId ? { employee_id: employeeId } : {} }).then(({ data }) => {
                setTodayData(data);
            }),
        ]).catch((err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }

            setError('Unable to load attendance.');
        });
    }, [params, employeeId, navigate]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!canViewOthers) return;

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function moveMonth(delta) {
        const next = shiftMonth(year, month, delta);

        setYear(next.year);
        setMonth(next.month);
        setSelected(null);
    }

    async function punch(direction) {
        setPunching(true);

        try {
            await api.post('/hrms/attendance/punch', { direction });

            toast.success(direction === 'in' ? 'Clocked in.' : 'Clocked out.');
            load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? 'Unable to record the punch.');
        } finally {
            setPunching(false);
        }
    }

    async function exportCsv() {
        if (!monthData) return;

        setExporting(true);

        try {
            const first = `${year}-${String(month).padStart(2, '0')}-01`;
            const lastDay = new Date(year, month, 0).getDate();
            const last = `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;

            const response = await api.get('/hrms/attendance/export', {
                params: { ...(employeeId ? { employee_id: employeeId } : {}), from: first, to: last },
                responseType: 'blob',
            });

            const url = window.URL.createObjectURL(new Blob([response.data], { type: 'text/csv' }));
            const link = document.createElement('a');

            link.href = url;
            link.download = `attendance-${monthData.employee?.employee_code ?? 'month'}-${first}_${last}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
        } catch (err) {
            toast.error('Unable to export attendance.');
        } finally {
            setExporting(false);
        }
    }

    function openCorrection(entry) {
        setSelected(entry);
        setCorrection({ firstIn: '', lastOut: '', reason: '' });
        setCorrectionErrors({});
        setCorrecting(true);
    }

    async function submitCorrection(e) {
        e.preventDefault();
        setCorrectionErrors({});

        if (!selected) return;

        const payload = { work_date: selected.date, reason: correction.reason };

        if (correction.firstIn) payload.requested_first_in_at = `${selected.date} ${correction.firstIn}:00`;
        if (correction.lastOut) payload.requested_punch_at = `${selected.date} ${correction.lastOut}:00`;

        try {
            await api.post('/hrms/attendance/regularizations', payload);

            toast.success('Correction requested. Your manager will review it.');
            setCorrecting(false);
            load();
        } catch (err) {
            setCorrectionErrors(fieldErrors(err));
        }
    }

    const days = monthData?.days ?? [];
    const matching = statusFilter ? days.filter((day) => day.status === statusFilter) : [];
    const summary = monthData?.summary ?? null;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Attendance</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {monthData ? `${monthData.employee?.name} · ${monthLabel(year, month)}` : '—'}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {canRegularize && (
                        <Link to="/hrms/attendance/approvals">
                            <Button variant="secondary">Review queue</Button>
                        </Link>
                    )}
                    <Button variant="secondary" onClick={exportCsv} disabled={exporting || !monthData}>
                        {exporting ? 'Exporting…' : 'Export CSV'}
                    </Button>
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="flex flex-wrap items-center gap-2">
                {canViewOthers && (
                    <Select
                        aria-label="Employee"
                        value={employeeId}
                        onChange={(e) => { setEmployeeId(e.target.value); setSelected(null); }}
                        className="w-56"
                    >
                        <option value="">Myself</option>
                        {(employees ?? []).map((employee) => (
                            <option key={employee.id} value={employee.id}>
                                {employee.name}
                            </option>
                        ))}
                    </Select>
                )}
                <Button variant="secondary" onClick={() => moveMonth(-1)}>‹ Prev</Button>
                <Button
                    variant="secondary"
                    onClick={() => { const current = new Date(); setYear(current.getFullYear()); setMonth(current.getMonth() + 1); setSelected(null); }}
                >
                    This month
                </Button>
                <Button variant="secondary" onClick={() => moveMonth(1)}>Next ›</Button>
                <Select aria-label="Status filter" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-44">
                    {STATUS_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <Card className="xl:col-span-2" title={monthLabel(year, month)} dense>
                    {!monthData ? (
                        <div className="flex justify-center py-10">
                            <Spinner />
                        </div>
                    ) : days.length === 0 ? (
                        <EmptyState title="No days yet" description="This month has no lived days to show." />
                    ) : (
                        <AttendanceCalendar year={year} month={month} days={days} selectedDate={selected?.date ?? null} onSelectDay={setSelected} />
                    )}

                    {statusFilter && (
                        <div className="mt-3 border-t border-gray-100 pt-3">
                            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">
                                {matching.length} day{matching.length === 1 ? '' : 's'} · {STATUS_OPTIONS.find((o) => o.value === statusFilter)?.label}
                            </p>
                            {matching.length === 0 ? (
                                <p className="text-sm text-gray-500">Nothing with this status this month.</p>
                            ) : (
                                <ul className="divide-y divide-gray-100">
                                    {matching.map((day) => (
                                        <li key={day.date}>
                                            <button type="button" onClick={() => setSelected(day)} className="flex w-full items-center justify-between py-1.5 text-left text-sm hover:text-indigo-600">
                                                <span className="font-medium">{day.date}</span>
                                                <span className="text-gray-500">{formatMinutes(day.worked_minutes)}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </Card>

                <div className="space-y-4">
                    <ClockInWidget today={todayData} punching={punching} onPunch={punch} allowPunch={!employeeId} />

                    <Card title="Month totals" dense>
                        {!summary ? (
                            <div className="flex justify-center py-6">
                                <Spinner />
                            </div>
                        ) : (
                            <dl className="grid grid-cols-2 gap-2 text-sm">
                                <div><dt className="text-gray-500">Present</dt><dd className="text-lg font-semibold text-gray-900">{summary.present}</dd></div>
                                <div><dt className="text-gray-500">Absent</dt><dd className="text-lg font-semibold text-gray-900">{summary.absent}</dd></div>
                                <div><dt className="text-gray-500">Late</dt><dd className="text-lg font-semibold text-gray-900">{summary.late}</dd></div>
                                <div><dt className="text-gray-500">Half day</dt><dd className="text-lg font-semibold text-gray-900">{summary.half_day}</dd></div>
                                <div><dt className="text-gray-500">Worked</dt><dd className="text-lg font-semibold text-gray-900">{formatMinutes(summary.worked_minutes)}</dd></div>
                                <div><dt className="text-gray-500">Overtime</dt><dd className="text-lg font-semibold text-gray-900">{formatMinutes(summary.overtime_minutes)}</dd></div>
                            </dl>
                        )}
                    </Card>

                    {selected && (
                        <Card title={selected.date} dense>
                            <dl className="space-y-1.5 text-sm">
                                <div className="flex justify-between"><dt className="text-gray-500">Status</dt><dd className="font-medium text-gray-900">{selected.status_label}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">First in</dt><dd className="text-gray-900">{selected.first_in_at ? new Date(selected.first_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">Last out</dt><dd className="text-gray-900">{selected.last_out_at ? new Date(selected.last_out_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">Worked</dt><dd className="text-gray-900">{formatMinutes(selected.worked_minutes)}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">Late by</dt><dd className="text-gray-900">{formatMinutes(selected.late_by_minutes)}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">Overtime</dt><dd className="text-gray-900">{formatMinutes(selected.overtime_minutes)}</dd></div>
                                {selected.is_regularized && <p className="pt-1 text-xs text-gray-500">Corrected through regularization.</p>}
                            </dl>
                            <div className="mt-3">
                                <Button variant="secondary" onClick={() => openCorrection(selected)}>Request correction</Button>
                            </div>
                        </Card>
                    )}
                </div>
            </div>

            <Modal open={correcting} onClose={() => setCorrecting(false)} title={`Correct ${selected?.date ?? ''}`}>
                <form onSubmit={submitCorrection} className="space-y-3">
                    <p className="text-sm text-gray-500">
                        Ask your manager to correct this day. At least one corrected time is required.
                    </p>
                    <div className="grid grid-cols-2 gap-3">
                        <Input
                            type="time"
                            label="Corrected clock-in"
                            value={correction.firstIn}
                            onChange={(e) => setCorrection((c) => ({ ...c, firstIn: e.target.value }))}
                            error={correctionErrors.requested_first_in_at}
                        />
                        <Input
                            type="time"
                            label="Corrected clock-out"
                            value={correction.lastOut}
                            onChange={(e) => setCorrection((c) => ({ ...c, lastOut: e.target.value }))}
                            error={correctionErrors.requested_punch_at}
                        />
                    </div>
                    <Input
                        label="Reason"
                        value={correction.reason}
                        onChange={(e) => setCorrection((c) => ({ ...c, reason: e.target.value }))}
                        error={correctionErrors.reason}
                        placeholder="Forgot to clock in at the gate…"
                    />
                    {correctionErrors.form && <Alert>{correctionErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setCorrecting(false)}>Cancel</Button>
                        <Button type="submit">Send request</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
