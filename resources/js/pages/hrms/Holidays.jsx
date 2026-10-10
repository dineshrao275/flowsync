import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import MetricCard from '../../components/ui/MetricCard';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';

const HOLIDAY_TYPES = [
    { value: 'public', label: 'Public' },
    { value: 'restricted', label: 'Restricted' },
    { value: 'optional', label: 'Optional' },
];

const LEAVE_TYPES = [
    { name: 'Paid time off', balance: '25 days', color: '#4B5EF5' },
    { name: 'Sick leave', balance: '10 days', color: '#1F9B69' },
    { name: 'Comp-off', balance: '3 days', color: '#DA972E' },
    { name: 'Maternity / parental', balance: 'Policy based', color: '#7B61FF' },
];

const UPCOMING_HOLIDAYS_MOCK = [
    { date: 'Oct 24', name: 'Diwali' },
    { date: 'Oct 25', name: 'Regional holiday' },
    { date: 'Nov 01', name: 'Foundation day' },
    { date: 'Nov 12', name: 'Festival holiday' },
];

const emptyHoliday = { name: '', date: '', type: 'public', is_recurring: true, description: '' };

export default function Holidays() {
    usePageTitle('Leave calendar & policies');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canManage = can('permission:hrms.holidays.manage') || can('hrms.holidays.manage');

    const [calendars, setCalendars] = useState(null);
    const [calendarId, setCalendarId] = useState('');
    const [_holidays, setHolidays] = useState(null);
    const [year] = useState(String(new Date().getFullYear()));
    const [error, setError] = useState(null);

    const [editingHoliday, setEditingHoliday] = useState(null);
    const [holidayModal, setHolidayModal] = useState(false);
    const [holidayForm, setHolidayForm] = useState(emptyHoliday);
    const [holidayErrors, setHolidayErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Leave calendar & policies' }]);
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

    const loadCalendars = useCallback(() => {
        setError(null);
        return api
            .get('/hrms/holidays/calendars')
            .then(({ data }) => {
                const list = data.calendars ?? [];
                setCalendars(list);
                if (list.length > 0 && !calendarId) {
                    const def = list.find((c) => c.is_default) ?? list[0];
                    setCalendarId(String(def.id));
                }
            })
            .catch(fail('Unable to load holiday calendars.'));
    }, [calendarId, fail]);

    const loadHolidays = useCallback(() => {
        if (!calendarId) {
            setHolidays([]);
            return;
        }
        return api
            .get(`/hrms/holidays/calendars/${calendarId}/holidays`, { params: { year } })
            .then(({ data }) => setHolidays(data.holidays ?? []))
            .catch(fail('Unable to load holidays for this calendar.'));
    }, [calendarId, year, fail]);

    useEffect(() => {
        loadCalendars();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        loadHolidays();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [calendarId, year]);

    async function saveHoliday(e) {
        e.preventDefault();
        setHolidayErrors({});
        try {
            if (editingHoliday) {
                await api.put(`/hrms/holidays/holidays/${editingHoliday.id}`, holidayForm);
                toast.success('Holiday updated.');
            } else {
                await api.post(`/hrms/holidays/calendars/${calendarId}/holidays`, holidayForm);
                toast.success('Holiday added.');
            }
            setHolidayModal(false);
            loadHolidays();
            loadCalendars();
        } catch (err) {
            setHolidayErrors(fieldErrors(err));
        }
    }

    // Days grid for October 2026 (starts on Thursday, Day 1)
    // Sunday (col 1) = empty, Mon (col 2) = empty, Tue (col 3) = empty
    // Wed (col 4) = 1, Thu (col 5) = 2, Fri (col 6) = 3, Sat (col 7) = 4
    const CALENDAR_DAYS = [
        { day: null },
        { day: null },
        { day: null },
        { day: 1 },
        { day: 2 },
        { day: 3 },
        { day: 4 },
        { day: 5 },
        { day: 6, tag: 'Holiday', tagType: 'holiday' },
        { day: 7 },
        { day: 8, tag: 'Leave', tagType: 'leave' },
        { day: 9 },
        { day: 10 },
        { day: 11 },
        { day: 12, tag: 'Holiday', tagType: 'holiday' },
        { day: 13 },
        { day: 14 },
        { day: 15 },
        { day: 16, tag: 'Leave', tagType: 'leave' },
        { day: 17 },
        { day: 18, tag: 'Holiday', tagType: 'holiday' },
        { day: 19 },
        { day: 20 },
        { day: 21 },
        { day: 22 },
        { day: 23 },
        { day: 24, tag: 'Leave', tagType: 'leave' },
        { day: 25 },
        { day: 26 },
        { day: 27 },
        { day: 28 },
        { day: 29 },
        { day: 30, tag: 'Holiday', tagType: 'holiday' },
        { day: 31 },
    ];

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">
                        Leave calendar & policies
                    </h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Manage team availability, leave types, balances and policy rules.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => {
                                setEditingHoliday(null);
                                setHolidayForm(emptyHoliday);
                                setHolidayModal(true);
                            }}
                            className="inline-flex items-center justify-center rounded-lg bg-[#4B5EF5] px-4 py-2.5 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                        >
                            + Add leave type
                        </button>
                    )}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="People on leave"
                    value={28}
                    pillText="Today"
                    pillVariant="healthy"
                    accentColor="#DA972E"
                />
                <MetricCard
                    label="Pending requests"
                    value={12}
                    pillText="Review"
                    pillVariant="healthy"
                    accentColor="#4B5EF5"
                />
                <MetricCard
                    label="Leave utilization"
                    value="71%"
                    pillText="+5%"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
                <MetricCard
                    label="Upcoming holidays"
                    value={4}
                    pillText="This month"
                    pillVariant="healthy"
                    accentColor="#7B61FF"
                />
            </div>

            {/* Middle Section: Team leave calendar (Left) & Types/Holidays (Right) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                {/* Left: Team leave calendar */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-8">
                    <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h2 className="text-[16px] font-semibold text-[#171C2C]">Team leave calendar</h2>
                            <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                                October 2026 · Approved leave updates TMS availability.
                            </p>
                        </div>

                        {calendars && calendars.length > 1 && (
                            <Select
                                value={calendarId}
                                onChange={(e) => setCalendarId(e.target.value)}
                                className="w-48 text-[12px]"
                            >
                                {calendars.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </div>

                    {/* Calendar Grid */}
                    <div className="space-y-2">
                        {/* Days of week header */}
                        <div className="grid grid-cols-7 text-center text-[11px] font-semibold tracking-wider text-[#8C96A8]">
                            <div>SUN</div>
                            <div>MON</div>
                            <div>TUE</div>
                            <div>WED</div>
                            <div>THU</div>
                            <div>FRI</div>
                            <div>SAT</div>
                        </div>

                        {/* Calendar cells */}
                        <div className="grid grid-cols-7 gap-2">
                            {CALENDAR_DAYS.map((cell, idx) => {
                                if (!cell.day) {
                                    return <div key={`empty-${idx}`} className="h-14 rounded-xl border border-transparent" />;
                                }

                                return (
                                    <div
                                        key={cell.day}
                                        className={`flex h-14 flex-col justify-between rounded-xl border p-2 transition-colors ${
                                            cell.tagType === 'holiday'
                                                ? 'border-[#D9E1FC] bg-[#F2F5FE]'
                                                : cell.tagType === 'leave'
                                                ? 'border-[#FDEBD2] bg-[#FFFBF4]'
                                                : 'border-[#F0F2F7] bg-white hover:border-[#E5E8F0]'
                                        }`}
                                    >
                                        <div className="text-[12px] font-semibold text-[#171C2C]">{cell.day}</div>
                                        {cell.tag && (
                                            <div
                                                className={`rounded px-1.5 py-0.5 text-center text-[10px] font-medium ${
                                                    cell.tagType === 'holiday'
                                                        ? 'bg-[#E9ECFF] text-[#4B5EF5]'
                                                        : 'bg-[#FFF3D9] text-[#DA972E]'
                                                }`}
                                            >
                                                {cell.tag}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* Right: Leave types & Upcoming holidays */}
                <div className="space-y-6 lg:col-span-4">
                    {/* Leave types Card */}
                    <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                        <div className="mb-4">
                            <h2 className="text-[16px] font-semibold text-[#171C2C]">Leave types</h2>
                            <p className="mt-0.5 text-[12px] text-[#8C96A8]">Policy status and balance rules</p>
                        </div>

                        <div className="divide-y divide-[#F0F2F7]">
                            {LEAVE_TYPES.map((lt) => (
                                <div key={lt.name} className="flex items-center justify-between py-3 first:pt-1 last:pb-1">
                                    <div className="flex items-center gap-2.5">
                                        <span className="h-2 w-2 rounded-full" style={{ backgroundColor: lt.color }} />
                                        <span className="text-[13px] font-medium text-[#171C2C]">{lt.name}</span>
                                    </div>
                                    <span className="text-[12px] text-[#8C96A8]">{lt.balance}</span>
                                </div>
                            ))}
                        </div>

                        <div className="mt-4 pt-2">
                            <button
                                type="button"
                                onClick={() => navigate('/hrms/leave')}
                                className="w-full rounded-lg border border-[#E5E8F0] bg-white py-2 text-center text-[12px] font-medium text-[#171C2C] hover:bg-[#F8FAFD] transition-colors shadow-xs"
                            >
                                Manage policies
                            </button>
                        </div>
                    </div>

                    {/* Upcoming holidays Card */}
                    <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                        <div className="mb-4">
                            <h2 className="text-[16px] font-semibold text-[#171C2C]">Upcoming holidays</h2>
                            <p className="mt-0.5 text-[12px] text-[#8C96A8]">Organization calendar</p>
                        </div>

                        <div className="divide-y divide-[#F0F2F7]">
                            {UPCOMING_HOLIDAYS_MOCK.map((uh) => (
                                <div key={uh.name} className="flex items-center gap-3 py-3 first:pt-1 last:pb-1">
                                    <span className="rounded-md bg-[#F4F6FB] px-2.5 py-1 text-[11px] font-semibold text-[#171C2C]">
                                        {uh.date}
                                    </span>
                                    <span className="text-[13px] font-medium text-[#171C2C]">{uh.name}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {/* Holiday editor modal */}
            <Modal open={holidayModal} onClose={() => setHolidayModal(false)} title="Add / Edit holiday" size="md">
                <form onSubmit={saveHoliday} className="space-y-4">
                    <Input
                        label="Name"
                        value={holidayForm.name}
                        onChange={(e) => setHolidayForm({ ...holidayForm, name: e.target.value })}
                        error={holidayErrors.name}
                        placeholder="e.g. Diwali"
                        required
                    />
                    <Input
                        label="Date"
                        type="date"
                        value={holidayForm.date}
                        onChange={(e) => setHolidayForm({ ...holidayForm, date: e.target.value })}
                        error={holidayErrors.date}
                        required
                    />
                    <Select
                        label="Type"
                        value={holidayForm.type}
                        onChange={(e) => setHolidayForm({ ...holidayForm, type: e.target.value })}
                    >
                        {HOLIDAY_TYPES.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </Select>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setHolidayModal(false)}>
                            Cancel
                        </Button>
                        <Button type="submit">Save holiday</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
