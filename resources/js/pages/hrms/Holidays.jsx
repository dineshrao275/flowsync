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
import { Table, Th, Td, TableEmpty } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import HolidayGrid from '../../components/hrms/HolidayGrid';
import HolidayImportModal from '../../components/hrms/HolidayImportModal';

const HOLIDAY_TYPES = [
    { value: 'public', label: 'Public' },
    { value: 'restricted', label: 'Restricted' },
    { value: 'optional', label: 'Optional' },
];

const PRESETS = [
    { name: "New Year's Day", month: 1, day: 1, type: 'public' },
    { name: 'Christmas Day', month: 12, day: 25, type: 'public' },
    { name: 'Diwali', month: 10, day: 20, type: 'optional' },
    { name: 'Holi', month: 3, day: 4, type: 'optional' },
];

const emptyCalendar = { name: '', country: '', description: '', is_default: false, is_active: true };
const emptyHoliday = { name: '', date: '', type: 'public', is_recurring: true, description: '' };

/**
 * Holiday calendars: the catalogue, the year grid, assignments, and the
 * optional panel.
 *
 * One page with internal gating: reads ride the module, while every
 * mutation hides without `hrms.holidays.manage` (the backend 403s
 * regardless). The grid renders stored rows with client-side recurring
 * expansion; presets fill the add-form in one click rather than posting
 * anything themselves.
 */
export default function Holidays() {
    usePageTitle('Holidays');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canManage = can('permission:hrms.holidays.manage');

    const [calendars, setCalendars] = useState(null);
    const [calendarId, setCalendarId] = useState('');
    const [holidays, setHolidays] = useState(null);
    const [year, setYear] = useState(String(new Date().getFullYear()));
    const [assignments, setAssignments] = useState(null);
    const [optionals, setOptionals] = useState(null);
    const [employees, setEmployees] = useState([]);
    const [error, setError] = useState(null);

    const [editingCalendar, setEditingCalendar] = useState(null);
    const [calendarModal, setCalendarModal] = useState(false);
    const [calendarForm, setCalendarForm] = useState(emptyCalendar);
    const [calendarErrors, setCalendarErrors] = useState({});

    const [editingHoliday, setEditingHoliday] = useState(null);
    const [holidayModal, setHolidayModal] = useState(false);
    const [holidayForm, setHolidayForm] = useState(emptyHoliday);
    const [holidayErrors, setHolidayErrors] = useState({});

    const [assignForm, setAssignForm] = useState({ employee_id: '', calendar_id: '', effective_from: '', effective_to: '' });
    const [assignErrors, setAssignErrors] = useState({});
    const [declareForm, setDeclareForm] = useState({ holiday_id: '', status: 'taken', taken_date: '', note: '' });
    const [declareErrors, setDeclareErrors] = useState({});
    const [seeding, setSeeding] = useState(false);
    const [importing, setImporting] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Holidays' }]);
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
                const rows = data.calendars ?? [];

                setCalendars(rows);
                setCalendarId((current) => {
                    if (current && rows.some((row) => String(row.id) === String(current))) return current;

                    const fallback = rows.find((row) => row.is_default) ?? rows[0];

                    return fallback ? String(fallback.id) : '';
                });
            })
            .catch(fail('Unable to load holiday calendars.'));
    }, [fail]);

    const loadHolidays = useCallback(() => {
        if (!calendarId) {
            setHolidays(null);

            return Promise.resolve();
        }

        return api
            .get(`/hrms/holidays/calendars/${calendarId}/holidays`)
            .then(({ data }) => setHolidays(data.holidays ?? []))
            .catch(fail('Unable to load holidays.'));
    }, [calendarId, fail]);

    const loadPeople = useCallback(() => {
        return Promise.all([
            api.get('/hrms/holidays/assignments').then(({ data }) => setAssignments(data.assignments ?? [])),
            api.get('/hrms/holidays/optional').then(({ data }) => setOptionals(data.optionals ?? [])),
        ]).catch(fail('Unable to load assignments.'));
    }, [fail]);

    useEffect(() => {
        loadCalendars();
        loadPeople();

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        loadHolidays();
    }, [loadHolidays]);

    async function saveCalendar(e) {
        e.preventDefault();
        setCalendarErrors({});

        try {
            if (editingCalendar) {
                await api.put(`/hrms/holidays/calendars/${editingCalendar.id}`, calendarForm);
                toast.success('Calendar updated.');
            } else {
                await api.post('/hrms/holidays/calendars', calendarForm);
                toast.success('Calendar created.');
            }

            setEditingCalendar(null);
            setCalendarModal(false);
            loadCalendars();
        } catch (err) {
            setCalendarErrors(fieldErrors(err));
        }
    }

    async function deleteCalendar(id) {
        if (!window.confirm('Delete this calendar? Calendars in use refuse deletion.')) return;

        try {
            await api.delete(`/hrms/holidays/calendars/${id}`);

            toast.success('Calendar deleted.');
            loadCalendars();
        } catch (err) {
            toast.error(err.response?.data?.errors?.form?.[0] ?? 'Unable to delete the calendar.');
        }
    }

    async function saveHoliday(e) {
        e.preventDefault();
        setHolidayErrors({});

        try {
            if (editingHoliday) {
                await api.put(`/hrms/holidays/${editingHoliday.id}`, holidayForm);
                toast.success('Holiday updated.');
            } else {
                await api.post(`/hrms/holidays/calendars/${calendarId}/holidays`, holidayForm);
                toast.success('Holiday created.');
            }

            closeHolidayModal();
            loadHolidays();
        } catch (err) {
            setHolidayErrors(fieldErrors(err));
        }
    }

    async function deleteHoliday(id) {
        if (!window.confirm('Delete this holiday? Answered holidays refuse deletion.')) return;

        try {
            await api.delete(`/hrms/holidays/${id}`);

            toast.success('Holiday deleted.');
            loadHolidays();
        } catch (err) {
            toast.error(err.response?.data?.errors?.form?.[0] ?? 'Unable to delete the holiday.');
        }
    }

    function fillPreset(preset) {
        const date = `${year}-${String(preset.month).padStart(2, '0')}-${String(preset.day).padStart(2, '0')}`;

        setEditingHoliday(null);
        setHolidayForm({ name: preset.name, date, type: preset.type, is_recurring: true, description: '' });
        setHolidayErrors({});
        setHolidayModal(true);
    }

    async function submitAssign(e) {
        e.preventDefault();
        setAssignErrors({});

        try {
            await api.post('/hrms/holidays/assignments', {
                employee_id: Number(assignForm.employee_id),
                calendar_id: Number(assignForm.calendar_id),
                effective_from: assignForm.effective_from,
                ...(assignForm.effective_to ? { effective_to: assignForm.effective_to } : {}),
            });

            toast.success('Calendar assigned.');
            setAssignForm({ employee_id: '', calendar_id: '', effective_from: '', effective_to: '' });
            loadPeople();
        } catch (err) {
            setAssignErrors(fieldErrors(err));
        }
    }

    async function unassign(id) {
        if (!window.confirm('Remove this assignment?')) return;

        try {
            await api.delete(`/hrms/holidays/assignments/${id}`);

            toast.success('Assignment removed.');
            loadPeople();
        } catch (err) {
            toast.error('Unable to remove the assignment.');
        }
    }

    async function declare(e) {
        e.preventDefault();
        setDeclareErrors({});

        try {
            await api.post('/hrms/holidays/optional', {
                holiday_id: Number(declareForm.holiday_id),
                status: declareForm.status,
                ...(declareForm.taken_date ? { taken_date: declareForm.taken_date } : {}),
                ...(declareForm.note ? { note: declareForm.note } : {}),
            });

            toast.success('Optional holiday declared.');
            setDeclareForm({ holiday_id: '', status: 'taken', taken_date: '', note: '' });
            loadPeople();
        } catch (err) {
            setDeclareErrors(fieldErrors(err));
        }
    }

    async function seedYear() {
        if (!window.confirm(`Expand ${year} from the catalogue? Reruns add nothing new.`)) return;

        setSeeding(true);

        try {
            const { data } = await api.post('/hrms/holidays/seed-year', { year: Number(year) });

            toast.success(`Year seeded: ${data.holidays} holidays.`);
            loadCalendars();
            loadHolidays();
        } catch (err) {
            toast.error('Unable to seed the year.');
        } finally {
            setSeeding(false);
        }
    }

    function openCalendarEditor(calendar) {
        setEditingCalendar(calendar ?? null);
        setCalendarForm(calendar ? { ...emptyCalendar, ...calendar } : emptyCalendar);
        setCalendarErrors({});
        setCalendarModal(true);
    }

    function closeCalendarModal() {
        setEditingCalendar(null);
        setCalendarModal(false);
        setCalendarForm(emptyCalendar);
        setCalendarErrors({});
    }

    function openHolidayEditor(holiday) {
        setEditingHoliday(holiday ?? null);
        setHolidayForm(
            holiday
                ? { name: holiday.name, date: holiday.date, type: holiday.type, is_recurring: holiday.is_recurring, description: holiday.description ?? '' }
                : emptyHoliday,
        );
        setHolidayErrors({});
        setHolidayModal(true);
    }

    function closeHolidayModal() {
        setEditingHoliday(null);
        setHolidayModal(false);
        setHolidayForm(emptyHoliday);
        setHolidayErrors({});
    }

    const selectedCalendar = calendars?.find((row) => String(row.id) === String(calendarId)) ?? null;
    const optionalHolidays = (holidays ?? []).filter((holiday) => holiday.type !== 'public');

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Holidays</h2>
                    <p className="mt-0.5 text-sm text-gray-500">Calendars, the year grid, assignments, optionals.</p>
                </div>

                {canManage && (
                    <div className="flex items-center gap-2">
                        <Input aria-label="Year" value={year} onChange={(e) => setYear(e.target.value)} className="w-24" />
                        <Button variant="secondary" onClick={seedYear} disabled={seeding}>
                            {seeding ? 'Seeding…' : 'Seed year'}
                        </Button>
                        <Button onClick={() => openCalendarEditor(null)}>New calendar</Button>
                    </div>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense title="Calendars">
                {!calendars ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : calendars.length === 0 ? (
                    <EmptyState title="No calendars yet" description="Seed a year or create one." />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Country</Th>
                                <Th>Holidays</Th>
                                <Th>Default</Th>
                                <Th><span className="sr-only">Actions</span></Th>
                            </tr>
                        </thead>
                        <tbody>
                            {calendars.map((calendar) => (
                                <tr key={calendar.id}>
                                    <Td>
                                        <button type="button" onClick={() => setCalendarId(String(calendar.id))} className="font-medium hover:text-indigo-600">
                                            {calendar.name}
                                        </button>
                                        {calendar.slug && <span className="ml-2 font-mono text-xs text-gray-400">{calendar.slug}</span>}
                                    </Td>
                                    <Td>{calendar.country ?? '—'}</Td>
                                    <Td>{calendar.holidays_count}</Td>
                                    <Td>{calendar.is_default ? 'Yes' : 'No'}</Td>
                                    <Td>
                                        {canManage && (
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => openCalendarEditor(calendar)}>Edit</Button>
                                                <Button variant="secondary" onClick={() => deleteCalendar(calendar.id)}>Delete</Button>
                                            </div>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            {selectedCalendar && (
                <Card dense title={`${selectedCalendar.name} · ${year}`}>
                    {!holidays ? (
                        <div className="flex justify-center py-6"><Spinner /></div>
                    ) : (
                        <>
                            <HolidayGrid holidays={holidays} year={Number(year)} />

                            <div className="mt-4">
                                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <h4 className="text-sm font-semibold text-gray-900">Holidays ({holidays.length})</h4>
                                    {canManage && (
                                        <div className="flex gap-2">
                                            <Button variant="secondary" onClick={() => setImporting(true)}>Import</Button>
                                            <Button onClick={() => openHolidayEditor(null)}>Add holiday</Button>
                                        </div>
                                    )}
                                </div>

                                {canManage && (
                                    <div className="mb-3 flex flex-wrap gap-1.5">
                                        {PRESETS.map((preset) => (
                                            <button
                                                key={preset.name}
                                                type="button"
                                                onClick={() => fillPreset(preset)}
                                                className="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-600 hover:bg-gray-200"
                                            >
                                                + {preset.name}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                <Table>
                                    <thead>
                                        <tr>
                                            <Th>Name</Th>
                                            <Th>Date</Th>
                                            <Th>Type</Th>
                                            <Th>Recurring</Th>
                                            {canManage && <Th><span className="sr-only">Actions</span></Th>}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {holidays.map((holiday) => (
                                            <tr key={holiday.id}>
                                                <Td><span className="font-medium">{holiday.name}</span></Td>
                                                <Td>{holiday.date}</Td>
                                                <Td>{holiday.type_label ?? holiday.type}</Td>
                                                <Td>{holiday.is_recurring ? 'Yes' : 'No'}</Td>
                                                {canManage && (
                                                    <Td>
                                                        <div className="flex justify-end gap-2">
                                                            <Button variant="secondary" onClick={() => openHolidayEditor(holiday)}>Edit</Button>
                                                            <Button variant="secondary" onClick={() => deleteHoliday(holiday.id)}>Delete</Button>
                                                        </div>
                                                    </Td>
                                                )}
                                            </tr>
                                        ))}
                                        {holidays.length === 0 && <TableEmpty colSpan={canManage ? 5 : 4}>No holidays on this calendar yet.</TableEmpty>}
                                    </tbody>
                                </Table>
                            </div>
                        </>
                    )}
                </Card>
            )}

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Card dense title="Assignments">
                    {!assignments ? (
                        <div className="flex justify-center py-6"><Spinner /></div>
                    ) : (
                        <>
                            {assignments.length > 0 && (
                                <ul className="mb-3 divide-y divide-gray-100">
                                    {assignments.map((assignment) => (
                                        <li key={assignment.id} className="flex items-center justify-between gap-3 py-1.5 text-sm">
                                            <span>
                                                <span className="font-medium">{assignment.employee?.name}</span>
                                                <span className="text-gray-500"> → {assignment.calendar?.name} from {assignment.effective_from}</span>
                                            </span>
                                            {canManage && (
                                                <Button variant="secondary" onClick={() => unassign(assignment.id)}>Remove</Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {canManage && (
                                <form onSubmit={submitAssign} className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <Select aria-label="Employee" value={assignForm.employee_id} onChange={(e) => setAssignForm({ ...assignForm, employee_id: e.target.value })} error={assignErrors.employee_id}>
                                        <option value="">Employee…</option>
                                        {employees.map((employee) => (
                                            <option key={employee.id} value={employee.id}>{employee.name}</option>
                                        ))}
                                    </Select>
                                    <Select aria-label="Calendar" value={assignForm.calendar_id} onChange={(e) => setAssignForm({ ...assignForm, calendar_id: e.target.value })} error={assignErrors.calendar_id}>
                                        <option value="">Calendar…</option>
                                        {(calendars ?? []).map((calendar) => (
                                            <option key={calendar.id} value={calendar.id}>{calendar.name}</option>
                                        ))}
                                    </Select>
                                    <Input type="date" aria-label="From" value={assignForm.effective_from} onChange={(e) => setAssignForm({ ...assignForm, effective_from: e.target.value })} error={assignErrors.effective_from} />
                                    <Input type="date" aria-label="To (optional)" value={assignForm.effective_to} onChange={(e) => setAssignForm({ ...assignForm, effective_to: e.target.value })} error={assignErrors.effective_to} />
                                    {assignErrors.form && <Alert>{assignErrors.form}</Alert>}
                                    <div className="sm:col-span-2">
                                        <Button type="submit">Assign</Button>
                                    </div>
                                </form>
                            )}
                        </>
                    )}
                </Card>

                <Card dense title="Optional holidays">
                    {!optionals ? (
                        <div className="flex justify-center py-6"><Spinner /></div>
                    ) : (
                        <>
                            {optionals.length > 0 && (
                                <ul className="mb-3 divide-y divide-gray-100">
                                    {optionals.map((answer) => (
                                        <li key={answer.id} className="flex items-center justify-between gap-3 py-1.5 text-sm">
                                            <span>
                                                <span className="font-medium">{answer.employee?.name}</span>
                                                <span className="text-gray-500"> · {answer.holiday?.name} · {answer.status_label}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <form onSubmit={declare} className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <Select aria-label="Holiday" value={declareForm.holiday_id} onChange={(e) => setDeclareForm({ ...declareForm, holiday_id: e.target.value })} error={declareErrors.holiday_id} className="sm:col-span-2">
                                    <option value="">Holiday…</option>
                                    {optionalHolidays.map((holiday) => (
                                        <option key={holiday.id} value={holiday.id}>{holiday.name} · {holiday.date}</option>
                                    ))}
                                </Select>
                                <Select aria-label="Answer" value={declareForm.status} onChange={(e) => setDeclareForm({ ...declareForm, status: e.target.value })} error={declareErrors.status}>
                                    <option value="taken">Take it</option>
                                    <option value="skipped">Skip it</option>
                                </Select>
                                <Input type="date" aria-label="Taken date" value={declareForm.taken_date} onChange={(e) => setDeclareForm({ ...declareForm, taken_date: e.target.value })} error={declareErrors.taken_date} />
                                {declareErrors.form && <Alert>{declareErrors.form}</Alert>}
                                <div className="sm:col-span-2">
                                    <Button type="submit">Declare</Button>
                                </div>
                            </form>
                        </>
                    )}
                </Card>
            </div>

            {importing && selectedCalendar && (
                <HolidayImportModal
                    calendar={selectedCalendar}
                    onClose={() => setImporting(false)}
                    onDone={() => {
                        setImporting(false);
                        loadCalendars();
                        loadHolidays();
                    }}
                />
            )}

            <Modal open={calendarModal} onClose={closeCalendarModal} title={editingCalendar ? 'Edit calendar' : 'New calendar'}>
                <form onSubmit={saveCalendar} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Input label="Name" value={calendarForm.name} onChange={(e) => setCalendarForm({ ...calendarForm, name: e.target.value })} error={calendarErrors.name} />
                    <Input label="Country (2-letter, optional)" value={calendarForm.country} onChange={(e) => setCalendarForm({ ...calendarForm, country: e.target.value })} error={calendarErrors.country} />
                    <Input label="Description" value={calendarForm.description} onChange={(e) => setCalendarForm({ ...calendarForm, description: e.target.value })} error={calendarErrors.description} className="sm:col-span-2" />
                    <Select label="Default calendar" value={String(calendarForm.is_default)} onChange={(e) => setCalendarForm({ ...calendarForm, is_default: e.target.value === 'true' })} error={calendarErrors.is_default}>
                        <option value="false">No</option>
                        <option value="true">Yes</option>
                    </Select>
                    <Select label="Active" value={String(calendarForm.is_active)} onChange={(e) => setCalendarForm({ ...calendarForm, is_active: e.target.value === 'true' })} error={calendarErrors.is_active}>
                        <option value="true">Yes</option>
                        <option value="false">No</option>
                    </Select>
                    {calendarErrors.form && <Alert>{calendarErrors.form}</Alert>}
                    <div className="flex justify-end gap-2 sm:col-span-2">
                        <Button type="button" variant="secondary" onClick={closeCalendarModal}>Cancel</Button>
                        <Button type="submit">Save</Button>
                    </div>
                </form>
            </Modal>

            <Modal open={holidayModal} onClose={closeHolidayModal} title={editingHoliday ? 'Edit holiday' : 'New holiday'}>
                <form onSubmit={saveHoliday} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Input label="Name" value={holidayForm.name} onChange={(e) => setHolidayForm({ ...holidayForm, name: e.target.value })} error={holidayErrors.name} />
                    <Input type="date" label="Date" value={holidayForm.date} onChange={(e) => setHolidayForm({ ...holidayForm, date: e.target.value })} error={holidayErrors.date} />
                    <Select label="Type" value={holidayForm.type} onChange={(e) => setHolidayForm({ ...holidayForm, type: e.target.value })} error={holidayErrors.type}>
                        {HOLIDAY_TYPES.map((option) => (
                            <option key={option.value} value={option.value}>{option.label}</option>
                        ))}
                    </Select>
                    <Select label="Recurring" value={String(holidayForm.is_recurring)} onChange={(e) => setHolidayForm({ ...holidayForm, is_recurring: e.target.value === 'true' })} error={holidayErrors.is_recurring}>
                        <option value="true">Yes</option>
                        <option value="false">No</option>
                    </Select>
                    {holidayErrors.form && <Alert>{holidayErrors.form}</Alert>}
                    <div className="flex justify-end gap-2 sm:col-span-2">
                        <Button type="button" variant="secondary" onClick={closeHolidayModal}>Cancel</Button>
                        <Button type="submit">Save</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
