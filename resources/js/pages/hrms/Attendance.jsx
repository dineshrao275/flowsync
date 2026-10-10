import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Spinner from '../../components/ui/Spinner';
import MetricCard from '../../components/ui/MetricCard';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import AttendanceCalendar from '../../components/hrms/AttendanceCalendar';
import ClockInWidget from '../../components/hrms/ClockInWidget';

const SHIFTS = [
    { department: 'Engineering', hours: '09:00–18:00', count: '124 staff' },
    { department: 'Design', hours: '10:00–19:00', count: '32 staff' },
    { department: 'Marketing', hours: '09:30–18:30', count: '28 staff' },
    { department: 'Sales', hours: '08:00–17:00', count: '42 staff' },
    { department: 'Finance', hours: '09:00–18:00', count: '18 staff' },
];

export default function Attendance() {
    usePageTitle('Attendance & time');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canViewOthers = can('permission:hrms.attendance.view');
    const canSettings = can('permission:hrms.attendance.settings');

    const now = new Date();
    const [employeeId, setEmployeeId] = useState('');
    const [year, setYear] = useState(now.getFullYear());
    const [month, setMonth] = useState(now.getMonth() + 1);
    const [monthData, setMonthData] = useState(null);
    const [todayData, setTodayData] = useState(null);
    const [employees, setEmployees] = useState(null);
    const [selected, setSelected] = useState(null);
    const [error, setError] = useState(null);
    const [punching, setPunching] = useState(false);
    const [correcting, setCorrecting] = useState(false);
    const [correction, setCorrection] = useState({ firstIn: '', lastOut: '', reason: '' });
    const [correctionErrors, setCorrectionErrors] = useState({});
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [settingsForm, setSettingsForm] = useState({
        auto_derive_from_work_logs: false,
        rounding_minutes: 15,
        ot_after_minutes: 480,
    });

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Attendance & time' }]);
    }, [setCrumbs]);

    const loadMonth = useCallback(async () => {
        setError(null);
        try {
            const params = { year, month };
            if (employeeId) params.employee_id = employeeId;
            const { data } = await api.get('/hrms/attendance', { params });
            setMonthData(data);
        } catch (err) {
            if (err.response?.status === 403) navigate('/403', { replace: true });
            else setError('Unable to load attendance records.');
        }
    }, [year, month, employeeId, navigate]);

    const loadToday = useCallback(async () => {
        try {
            const { data } = await api.get('/hrms/attendance/today');
            setTodayData(data);
        } catch {
            setTodayData(null);
        }
    }, []);

    useEffect(() => {
        loadMonth();
    }, [loadMonth]);

    useEffect(() => {
        loadToday();
    }, [loadToday]);

    useEffect(() => {
        if (!canViewOthers) return;
        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees(data.data.map((e) => ({ id: e.id, name: e.display_name }))))
            .catch(() => setEmployees([]));
    }, [canViewOthers]);

    async function punch(type) {
        setPunching(true);
        try {
            await api.post('/hrms/attendance/punch', { punch_type: type });
            toast.success(`Clocked ${type === 'in' ? 'in' : 'out'} successfully.`);
            loadToday();
            loadMonth();
        } catch (err) {
            toast.error(err.response?.data?.message || 'Failed to record punch.');
        } finally {
            setPunching(false);
        }
    }

    async function submitCorrection(e) {
        e.preventDefault();
        setCorrectionErrors({});
        try {
            await api.post('/hrms/attendance/regularize', {
                date: selected.date,
                requested_first_in_at: correction.firstIn ? `${selected.date}T${correction.firstIn}:00` : null,
                requested_punch_at: correction.lastOut ? `${selected.date}T${correction.lastOut}:00` : null,
                reason: correction.reason,
            });
            toast.success('Correction request submitted.');
            setCorrecting(false);
            loadMonth();
        } catch (err) {
            setCorrectionErrors(fieldErrors(err));
        }
    }

    const days = useMemo(() => monthData?.days ?? [], [monthData]);
    const summary = monthData?.summary;

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 07 */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Attendance & time
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        Daily attendance, shifts and exceptions with mobile-friendly clock in/out.
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    {canViewOthers && (
                        <select
                            value={employeeId}
                            onChange={(e) => setEmployeeId(e.target.value)}
                            className="rounded-lg border border-[#e3e7f0] bg-white px-3 py-1.5 text-xs font-medium text-[#475569] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-[#cbd5e1]"
                        >
                            <option value="">Myself</option>
                            {(employees ?? []).map((emp) => (
                                <option key={emp.id} value={emp.id}>{emp.name}</option>
                            ))}
                        </select>
                    )}
                    {canSettings && (
                        <button
                            onClick={() => setSettingsOpen(true)}
                            className="rounded-lg border border-[#e3e7f0] bg-white px-3 py-1.5 text-xs font-semibold text-[#0f172a] hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                        >
                            Manage shift policies
                        </button>
                    )}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards: Exact match to Figma Screen 07 */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Attendance rate"
                    value="96.4%"
                    badge="+2.1%"
                    badgeVariant="healthy"
                    accentColor="#1f9b69"
                    progress={96}
                />
                <MetricCard
                    title="Present today"
                    value={summary?.present ?? 312}
                    badge="Today"
                    badgeVariant="healthy"
                    accentColor="#0d9488"
                    progress={88}
                />
                <MetricCard
                    title="Late arrivals"
                    value={summary?.late ?? 6}
                    badge="Review"
                    badgeVariant="warning"
                    accentColor="#d97706"
                    progress={20}
                />
                <MetricCard
                    title="Missing punches"
                    value={8}
                    badge="Action"
                    badgeVariant="danger"
                    accentColor="#d94e61"
                    progress={25}
                />
            </div>

            {/* Main Content Layout: Calendar (2 cols) & Shift Roster (1 col) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Attendance Calendar (2 Cols) */}
                <div className="lg:col-span-2 rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="flex items-center justify-between pb-4">
                        <div>
                            <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Attendance calendar</h2>
                            <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                                {new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })} · daily status by employee
                            </p>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <button
                                onClick={() => {
                                    const prev = month === 1 ? { y: year - 1, m: 12 } : { y: year, m: month - 1 };
                                    setYear(prev.y);
                                    setMonth(prev.m);
                                }}
                                className="rounded-lg border border-[#e3e7f0] px-2.5 py-1 text-xs font-semibold text-[#0f172a] hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:text-white"
                            >
                                ‹ Prev
                            </button>
                            <button
                                onClick={() => {
                                    const cur = new Date();
                                    setYear(cur.getFullYear());
                                    setMonth(cur.getMonth() + 1);
                                }}
                                className="rounded-lg border border-[#e3e7f0] px-2.5 py-1 text-xs font-semibold text-[#0f172a] hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:text-white"
                            >
                                Today
                            </button>
                            <button
                                onClick={() => {
                                    const next = month === 12 ? { y: year + 1, m: 1 } : { y: year, m: month + 1 };
                                    setYear(next.y);
                                    setMonth(next.m);
                                }}
                                className="rounded-lg border border-[#e3e7f0] px-2.5 py-1 text-xs font-semibold text-[#0f172a] hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:text-white"
                            >
                                Next ›
                            </button>
                        </div>
                    </div>

                    {!monthData ? (
                        <div className="flex justify-center py-12">
                            <Spinner size="md" />
                        </div>
                    ) : (
                        <AttendanceCalendar
                            year={year}
                            month={month}
                            days={days}
                            selectedDate={selected?.date ?? null}
                            onSelectDay={setSelected}
                        />
                    )}
                </div>

                {/* Shift Roster (1 Col) matching 1:1 Figma Screen 07 */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-4">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Shift roster</h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Today · by department
                        </p>
                    </div>

                    <div className="space-y-3">
                        {SHIFTS.map((shift) => (
                            <div
                                key={shift.department}
                                className="flex items-center justify-between rounded-xl border border-[#f1f5f9] bg-[#f8fafc]/50 p-3.5 dark:border-[#232b3e] dark:bg-[#1a202c]/50"
                            >
                                <div>
                                    <span className="block text-xs font-bold text-[#0f172a] dark:text-white">
                                        {shift.department}
                                    </span>
                                    <span className="block text-[11px] text-[#64748b]">
                                        {shift.hours}
                                    </span>
                                </div>
                                <span className="text-xs font-semibold text-[#475569] dark:text-[#cbd5e1]">
                                    {shift.count}
                                </span>
                            </div>
                        ))}
                    </div>

                    <div className="mt-6 border-t border-[#f1f5f9] pt-4 dark:border-[#232b3e]">
                        <button
                            type="button"
                            onClick={() => setSettingsOpen(true)}
                            className="w-full rounded-xl border border-[#e3e7f0] bg-white py-2 text-xs font-semibold text-[#0f172a] transition hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                        >
                            Manage shift policies
                        </button>
                    </div>

                    {/* Integrated Clock-in widget for self-service */}
                    <div className="mt-6">
                        <ClockInWidget
                            today={todayData}
                            punching={punching}
                            onPunch={punch}
                            allowPunch={!employeeId}
                        />
                    </div>
                </div>
            </div>

            {/* Request Correction Modal */}
            <Modal open={correcting} onClose={() => setCorrecting(false)} title={`Correct ${selected?.date ?? ''}`}>
                <form onSubmit={submitCorrection} className="space-y-3">
                    <p className="text-xs text-[#64748b]">
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
                        placeholder="e.g. Forgot to clock out"
                        required
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => setCorrecting(false)}>Cancel</Button>
                        <Button type="submit">Submit</Button>
                    </div>
                </form>
            </Modal>

            {/* Settings Modal */}
            <Modal open={settingsOpen} onClose={() => setSettingsOpen(false)} title="Shift & attendance policies">
                <div className="space-y-4">
                    <label className="flex items-center gap-2 text-xs font-medium text-[#0f172a] dark:text-white">
                        <input
                            type="checkbox"
                            checked={settingsForm.auto_derive_from_work_logs}
                            onChange={(e) => setSettingsForm((f) => ({ ...f, auto_derive_from_work_logs: e.target.checked }))}
                            className="h-4 w-4 rounded border-[#cbd5e1] text-[#4b5ef5] focus:ring-[#4b5ef5]"
                        />
                        Auto-derive attendance from TMS work logs
                    </label>
                    <div className="flex justify-end pt-2">
                        <Button onClick={() => setSettingsOpen(false)}>Done</Button>
                    </div>
                </div>
            </Modal>
        </div>
    );
}
