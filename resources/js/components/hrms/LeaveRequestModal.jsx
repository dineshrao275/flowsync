import { useCallback, useEffect, useMemo, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Modal from '../ui/Modal';
import Select from '../ui/Select';
import { useToast } from '../../context/ToastContext';

const HALVES = [
    { value: 'full', label: 'Full day' },
    { value: 'first_half', label: 'First half' },
    { value: 'second_half', label: 'Second half' },
];

const WEEKDAYS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

/**
 * A mini month grid showing who else is away on each date.
 *
 * Fridays the modal user reads before filing: the entries come from
 * `GET /hrms/leave/requests/calendar` (self plus direct reports), so a
 * day with two teammates already out reads as a cover problem before the
 * request is even sent.
 */
function TeamMiniCalendar({ year, month, days }) {
    const firstOffset = (new Date(year, month - 1, 1).getDay() + 6) % 7;
    const daysInMonth = new Date(year, month, 0).getDate();

    const cells = [];

    for (let i = 0; i < firstOffset; i++) {
        cells.push(<span key={`pad-${i}`} />);
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const date = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const entries = days[date] ?? [];

        cells.push(
            <span
                key={date}
                title={entries.length === 0 ? date : `${date}: ${entries.map((e) => `${e.employee_name} (${e.type_name})`).join(', ')}`}
                className={`flex h-7 items-center justify-center rounded text-xs ${
                    entries.length === 0 ? 'text-gray-400' : 'bg-indigo-100 font-semibold text-indigo-800'
                }`}
            >
                {day}
            </span>,
        );
    }

    return (
        <div>
            <div className="mb-1 grid grid-cols-7 gap-0.5">
                {WEEKDAYS.map((day, i) => (
                    <span key={i} className="text-center text-[11px] font-medium text-gray-400">
                        {day}
                    </span>
                ))}
            </div>
            <div className="grid grid-cols-7 gap-0.5">{cells}</div>
        </div>
    );
}

/**
 * Filing leave: type, range, halves, reason — with a live price.
 *
 * The availability preview (`GET …/requests/availability`) prices the
 * range as typed: total days, free days, and the shortfall warning when
 * the balance does not cover it. Filing anyway is allowed — the backend
 * still 422s the shortfall, and the warning quotes the same number so the
 * refusal never surprises. `employeeId` files for someone else (managers);
 * without it the ask files for the caller.
 */
export default function LeaveRequestModal({ open, onClose, onSaved, employeeId = null, employees = null, types = [] }) {
    const toast = useToast();

    const [form, setForm] = useState({ employee_id: '', leave_type_id: '', from_date: '', to_date: '', from_half: 'full', to_half: 'full', reason: '', contact_during_leave: '' });
    const [formErrors, setFormErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [quote, setQuote] = useState(null);
    const [teamDays, setTeamDays] = useState({});

    const set = useCallback(
        (key) => (e) => {
            const value = e.target?.value ?? e;
            setForm((current) => ({ ...current, [key]: value }));
        },
        [],
    );

    const quoteParams = useMemo(() => {
        if (!form.leave_type_id || !form.from_date || !form.to_date) return null;

        return {
            ...(form.employee_id ? { employee_id: Number(form.employee_id) } : employeeId ? { employee_id: Number(employeeId) } : {}),
            leave_type_id: Number(form.leave_type_id),
            from_date: form.from_date,
            to_date: form.to_date,
            from_half: form.from_half,
            to_half: form.to_half,
        };
    }, [form, employeeId]);

    useEffect(() => {
        if (!open) return;

        setForm({ employee_id: '', leave_type_id: '', from_date: '', to_date: '', from_half: 'full', to_half: 'full', reason: '', contact_during_leave: '' });
        setFormErrors({});
        setQuote(null);
    }, [open ]);

    useEffect(() => {
        if (!open || !quoteParams) {
            setQuote(null);

            return;
        }

        let cancelled = false;

        api.get('/hrms/leave/requests/availability', { params: quoteParams })
            .then(({ data }) => {
                if (!cancelled) setQuote(data);
            })
            .catch(() => {
                if (!cancelled) setQuote(null);
            });

        return () => {
            cancelled = true;
        };
    }, [open, quoteParams]);

    useEffect(() => {
        if (!open || !form.from_date) {
            setTeamDays({});

            return;
        }

        const [year, month] = form.from_date.split('-').map(Number);

        api.get('/hrms/leave/requests/calendar', { params: { year, month } })
            .then(({ data }) => setTeamDays(data.days ?? {}))
            .catch(() => setTeamDays({}));
    }, [open, form.from_date]);

    const shortfall = quote && quote.shortfall_days > 0;

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setFormErrors({});

        const payload = {
            leave_type_id: Number(form.leave_type_id),
            from_date: form.from_date,
            to_date: form.to_date,
            from_half: form.from_half,
            to_half: form.to_half,
            reason: form.reason,
            ...(form.contact_during_leave ? { contact_during_leave: form.contact_during_leave } : {}),
            ...(form.employee_id ? { employee_id: Number(form.employee_id) } : employeeId ? { employee_id: Number(employeeId) } : {}),
        };

        try {
            await api.post('/hrms/leave/requests', payload);

            toast.success('Leave requested. Your manager will review it.');
            onSaved();
        } catch (err) {
            setFormErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    const calendarYear = form.from_date ? Number(form.from_date.split('-')[0]) : new Date().getFullYear();
    const calendarMonth = form.from_date ? Number(form.from_date.split('-')[1]) : new Date().getMonth() + 1;

    return (
        <Modal open={open} onClose={onClose} title="Request leave" size="lg">
            <form onSubmit={submit} className="space-y-3">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {employees && (
                        <Select label="Employee" value={form.employee_id} onChange={set('employee_id')} error={formErrors.employee_id}>
                            <option value="">Myself</option>
                            {employees.map((employee) => (
                                <option key={employee.id} value={employee.id}>
                                    {employee.name}
                                </option>
                            ))}
                        </Select>
                    )}
                    <Select label="Leave type" value={form.leave_type_id} onChange={set('leave_type_id')} error={formErrors.leave_type_id}>
                        <option value="">Select a type…</option>
                        {types.map((type) => (
                            <option key={type.id} value={type.id}>
                                {type.name}
                            </option>
                        ))}
                    </Select>
                    <Input type="date" label="From" value={form.from_date} onChange={set('from_date')} error={formErrors.from_date} />
                    <Input type="date" label="To" value={form.to_date} onChange={set('to_date')} error={formErrors.to_date} />
                    <Select label="First day" value={form.from_half} onChange={set('from_half')} error={formErrors.from_half}>
                        {HALVES.map((half) => (
                            <option key={half.value} value={half.value}>
                                {half.label}
                            </option>
                        ))}
                    </Select>
                    <Select label="Last day" value={form.to_half} onChange={set('to_half')} error={formErrors.to_half}>
                        {HALVES.map((half) => (
                            <option key={half.value} value={half.value}>
                                {half.label}
                            </option>
                        ))}
                    </Select>
                </div>

                <Input label="Reason" value={form.reason} onChange={set('reason')} error={formErrors.reason} placeholder="A short break…" />
                <Input label="Contact while away (optional)" value={form.contact_during_leave} onChange={set('contact_during_leave')} error={formErrors.contact_during_leave} />

                {quote && (
                    <div className={`rounded-lg border px-3 py-2 text-sm ${shortfall ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-gray-200 bg-gray-50 text-gray-600'}`}>
                        {quote.total_days} day{quote.total_days === 1 ? '' : 's'} requested · {quote.available_days} free
                        {shortfall && ` · short by ${quote.shortfall_days} — filing will be refused until the balance covers it`}
                    </div>
                )}

                <div>
                    <p className="mb-1.5 text-sm font-medium text-gray-700">Team cover that month</p>
                    <TeamMiniCalendar year={calendarYear} month={calendarMonth} days={teamDays} />
                </div>

                {formErrors.form && <Alert>{formErrors.form}</Alert>}

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={saving}>
                        {saving ? 'Sending…' : 'Send request'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
