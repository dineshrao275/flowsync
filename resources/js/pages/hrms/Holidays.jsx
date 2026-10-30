import { useCallback, useEffect, useState } from 'react';
import UserChip from '../../components/ui/UserChip';
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
import { Table, Th, Td, TableEmpty } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import HolidayGrid from '../../components/hrms/HolidayGrid';
import HolidayImportModal from '../../components/hrms/HolidayImportModal';

const HOLIDAY_TYPES = [
    { value: 'public', key: 'typePublic' },
    { value: 'restricted', key: 'typeRestricted' },
    { value: 'optional', key: 'typeOptional' },
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
    const { t } = useTranslation();
    usePageTitle(t('hrms.holidays.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();

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
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.holidays.pageTitle') }]);
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
            .catch(fail('hrms.holidays.loadCalendarsFailed'));
    }, [fail]);

    const loadHolidays = useCallback(() => {
        if (!calendarId) {
            setHolidays(null);

            return Promise.resolve();
        }

        return api
            .get(`/hrms/holidays/calendars/${calendarId}/holidays`)
            .then(({ data }) => setHolidays(data.holidays ?? []))
            .catch(fail('hrms.holidays.loadHolidaysFailed'));
    }, [calendarId, fail]);

    const loadPeople = useCallback(() => {
        return Promise.all([
            api.get('/hrms/holidays/assignments').then(({ data }) => setAssignments(data.assignments ?? [])),
            api.get('/hrms/holidays/optional').then(({ data }) => setOptionals(data.optionals ?? [])),
        ]).catch(fail('hrms.holidays.loadAssignmentsFailed'));
    }, [fail]);

    useEffect(() => {
        loadCalendars();
        loadPeople();

        api.get('/hrms/employees', { params: { per_page: 250 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name, email: e.user?.email }))))
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
                toast.success(t('hrms.holidays.calendarUpdated'));
            } else {
                await api.post('/hrms/holidays/calendars', calendarForm);
                toast.success(t('hrms.holidays.calendarCreated'));
            }

            setEditingCalendar(null);
            setCalendarModal(false);
            loadCalendars();
        } catch (err) {
            setCalendarErrors(fieldErrors(err));
        }
    }

    async function deleteCalendar(id) {
        if (!(await confirm({ title: t('hrms.holidays.deleteCalendarTitle'), message: t('hrms.holidays.deleteCalendarMessage'), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/holidays/calendars/${id}`);

            toast.success(t('hrms.holidays.calendarDeleted'));
            loadCalendars();
        } catch (err) {
            toast.error(err.response?.data?.errors?.form?.[0] ?? t('hrms.holidays.calendarDeleteFailed'));
        }
    }

    async function saveHoliday(e) {
        e.preventDefault();
        setHolidayErrors({});

        try {
            if (editingHoliday) {
                await api.put(`/hrms/holidays/${editingHoliday.id}`, holidayForm);
                toast.success(t('hrms.holidays.holidayUpdated'));
            } else {
                await api.post(`/hrms/holidays/calendars/${calendarId}/holidays`, holidayForm);
                toast.success(t('hrms.holidays.holidayCreated'));
            }

            closeHolidayModal();
            loadHolidays();
        } catch (err) {
            setHolidayErrors(fieldErrors(err));
        }
    }

    async function deleteHoliday(id) {
        if (!(await confirm({ title: t('hrms.holidays.deleteHolidayTitle'), message: t('hrms.holidays.deleteHolidayMessage'), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/holidays/${id}`);

            toast.success(t('hrms.holidays.holidayDeleted'));
            loadHolidays();
        } catch (err) {
            toast.error(err.response?.data?.errors?.form?.[0] ?? t('hrms.holidays.holidayDeleteFailed'));
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

            toast.success(t('hrms.holidays.assigned'));
            setAssignForm({ employee_id: '', calendar_id: '', effective_from: '', effective_to: '' });
            loadPeople();
        } catch (err) {
            setAssignErrors(fieldErrors(err));
        }
    }

    async function unassign(id) {
        if (!(await confirm({ title: t('hrms.holidays.removeAssignTitle'), message: t('hrms.holidays.removeAssignMessage'), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/holidays/assignments/${id}`);

            toast.success(t('hrms.holidays.assignmentRemoved'));
            loadPeople();
        } catch {
            toast.error(t('hrms.holidays.unassignFailed'));
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

            toast.success(t('hrms.holidays.declared'));
            setDeclareForm({ holiday_id: '', status: 'taken', taken_date: '', note: '' });
            loadPeople();
        } catch (err) {
            setDeclareErrors(fieldErrors(err));
        }
    }

    async function seedYear() {
        if (!(await confirm({ title: t('hrms.holidays.seedTitle'), message: t('hrms.holidays.seedMessage', { year }) }))) return;

        setSeeding(true);

        try {
            const { data } = await api.post('/hrms/holidays/seed-year', { year: Number(year) });

            toast.success(t('hrms.holidays.seeded', { count: data.holidays }));
            loadCalendars();
            loadHolidays();
        } catch (err) {
            toast.error(t('hrms.holidays.seedFailed'));
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
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.holidays.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">{t('hrms.holidays.subtitle')}</p>
                </div>

                {canManage && (
                    <div className="flex items-center gap-2">
                        <Input aria-label={t('hrms.holidays.year')} value={year} onChange={(e) => setYear(e.target.value)} className="w-24" />
                        <Button variant="secondary" onClick={seedYear} disabled={seeding}>
                            {seeding ? t('hrms.holidays.seeding') : t('hrms.holidays.seedYear')}
                        </Button>
                        <Button onClick={() => openCalendarEditor(null)}>{t('hrms.holidays.newCalendar')}</Button>
                    </div>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense title={t('hrms.holidays.calendars')}>
                {!calendars ? (
                    <ContentSkeleton size="sm" />
                ) : calendars.length === 0 ? (
                    <EmptyState title={t('hrms.holidays.emptyCalendars')} description={t('hrms.holidays.emptyCalendarsHint')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.holidays.thName')}</Th>
                                <Th>{t('hrms.holidays.thCountry')}</Th>
                                <Th>{t('hrms.holidays.thHolidays')}</Th>
                                <Th>{t('hrms.holidays.thDefault')}</Th>
                                <Th><span className="sr-only">{t('hrms.holidays.thActions')}</span></Th>
                            </tr>
                        </thead>
                        <tbody>
                            {calendars.map((calendar) => (
                                <tr key={calendar.id}>
                                    <Td>
                                        <button type="button" onClick={() => setCalendarId(String(calendar.id))} className="font-medium hover:text-[var(--accent-soft-text)]">
                                            {calendar.name}
                                        </button>
                                        {calendar.slug && <span className="ml-2 font-mono text-xs text-gray-400">{calendar.slug}</span>}
                                    </Td>
                                    <Td>{calendar.country ?? '—'}</Td>
                                    <Td>{calendar.holidays_count}</Td>
                                    <Td>{calendar.is_default ? t('hrms.common.yes') : t('hrms.common.no')}</Td>
                                    <Td>
                                        {canManage && (
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => openCalendarEditor(calendar)}>{t('hrms.common.edit')}</Button>
                                                <Button variant="secondary" onClick={() => deleteCalendar(calendar.id)}>{t('hrms.common.delete')}</Button>
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
                        <ContentSkeleton size="sm" />
                    ) : (
                        <>
                            <HolidayGrid holidays={holidays} year={Number(year)} />

                            <div className="mt-4">
                                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <h4 className="text-sm font-semibold text-gray-900">{t('hrms.holidays.holidaysCount', { count: holidays.length })}</h4>
                                    {canManage && (
                                        <div className="flex gap-2">
                                            <Button variant="secondary" onClick={() => setImporting(true)}>{t('hrms.holidays.import')}</Button>
                                            <Button onClick={() => openHolidayEditor(null)}>{t('hrms.holidays.addHoliday')}</Button>
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
                                            <Th>{t('hrms.holidays.thName')}</Th>
                                            <Th>{t('hrms.holidays.thDate')}</Th>
                                            <Th>{t('hrms.holidays.thType')}</Th>
                                            <Th>{t('hrms.holidays.thRecurring')}</Th>
                                            {canManage && <Th><span className="sr-only">{t('hrms.holidays.thActions')}</span></Th>}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {holidays.map((holiday) => (
                                            <tr key={holiday.id}>
                                                <Td><span className="font-medium">{holiday.name}</span></Td>
                                                <Td>{holiday.date}</Td>
                                                <Td>{holiday.type_label ?? holiday.type}</Td>
                                                <Td>{holiday.is_recurring ? t('hrms.common.yes') : t('hrms.common.no')}</Td>
                                                {canManage && (
                                                    <Td>
                                                        <div className="flex justify-end gap-2">
                                                            <Button variant="secondary" onClick={() => openHolidayEditor(holiday)}>{t('hrms.common.edit')}</Button>
                                                            <Button variant="secondary" onClick={() => deleteHoliday(holiday.id)}>{t('hrms.common.delete')}</Button>
                                                        </div>
                                                    </Td>
                                                )}
                                            </tr>
                                        ))}
                                        {holidays.length === 0 && <TableEmpty colSpan={canManage ? 5 : 4}>{t('hrms.holidays.emptyCalendar')}</TableEmpty>}
                                    </tbody>
                                </Table>
                            </div>
                        </>
                    )}
                </Card>
            )}

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Card dense title={t('hrms.holidays.assignments')}>
                    {!assignments ? (
                        <ContentSkeleton size="sm" />
                    ) : (
                        <>
                            {assignments.length > 0 && (
                                <ul className="mb-3 divide-y divide-gray-100">
                                    {assignments.map((assignment) => (
                                        <li key={assignment.id} className="flex items-center justify-between gap-3 py-1.5 text-sm">
                                            <span>
                                                <span className="font-medium"><UserChip user={assignment.employee} /></span>
                                                <span className="text-gray-500"> → {assignment.calendar?.name} {t('hrms.holidays.assignFrom', { date: assignment.effective_from })}</span>
                                            </span>
                                            {canManage && (
                                                <Button variant="secondary" onClick={() => unassign(assignment.id)}>{t('hrms.holidays.remove')}</Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {canManage && (
                                <form onSubmit={submitAssign} className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <PersonSelect aria-label={t('hrms.holidays.employee')} value={assignForm.employee_id} onChange={(e) => setAssignForm({ ...assignForm, employee_id: e.target.value })} error={assignErrors.employee_id} placeholder={t('hrms.holidays.employeePlaceholder')} people={employees} />
                                    <Select aria-label={t('hrms.holidays.calendar')} value={assignForm.calendar_id} onChange={(e) => setAssignForm({ ...assignForm, calendar_id: e.target.value })} error={assignErrors.calendar_id}>
                                        <option value="">{t('hrms.holidays.calendarPlaceholder')}</option>
                                        {(calendars ?? []).map((calendar) => (
                                            <option key={calendar.id} value={calendar.id}>{calendar.name}</option>
                                        ))}
                                    </Select>
                                    <Input type="date" aria-label={t('hrms.holidays.from')} value={assignForm.effective_from} onChange={(e) => setAssignForm({ ...assignForm, effective_from: e.target.value })} error={assignErrors.effective_from} />
                                    <Input type="date" aria-label={t('hrms.holidays.toOptional')} value={assignForm.effective_to} onChange={(e) => setAssignForm({ ...assignForm, effective_to: e.target.value })} error={assignErrors.effective_to} />
                                    {assignErrors.form && <Alert>{assignErrors.form}</Alert>}
                                    <div className="sm:col-span-2">
                                        <Button type="submit">{t('hrms.holidays.assign')}</Button>
                                    </div>
                                </form>
                            )}
                        </>
                    )}
                </Card>

                <Card dense title={t('hrms.holidays.optionals')}>
                    {!optionals ? (
                        <ContentSkeleton size="sm" />
                    ) : (
                        <>
                            {optionals.length > 0 && (
                                <ul className="mb-3 divide-y divide-gray-100">
                                    {optionals.map((answer) => (
                                        <li key={answer.id} className="flex items-center justify-between gap-3 py-1.5 text-sm">
                                            <span>
                                                <span className="font-medium"><UserChip user={answer.employee} /></span>
                                                <span className="text-gray-500"> · {answer.holiday?.name} · {answer.status_label}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <form onSubmit={declare} className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <Select aria-label={t('hrms.holidays.holiday')} value={declareForm.holiday_id} onChange={(e) => setDeclareForm({ ...declareForm, holiday_id: e.target.value })} error={declareErrors.holiday_id} className="sm:col-span-2">
                                    <option value="">{t('hrms.holidays.holidayPlaceholder')}</option>
                                    {optionalHolidays.map((holiday) => (
                                        <option key={holiday.id} value={holiday.id}>{holiday.name} · {holiday.date}</option>
                                    ))}
                                </Select>
                                <Select aria-label={t('hrms.holidays.answer')} value={declareForm.status} onChange={(e) => setDeclareForm({ ...declareForm, status: e.target.value })} error={declareErrors.status}>
                                    <option value="taken">{t('hrms.holidays.takeIt')}</option>
                                    <option value="skipped">{t('hrms.holidays.skipIt')}</option>
                                </Select>
                                <Input type="date" aria-label={t('hrms.holidays.takenDate')} value={declareForm.taken_date} onChange={(e) => setDeclareForm({ ...declareForm, taken_date: e.target.value })} error={declareErrors.taken_date} />
                                {declareErrors.form && <Alert>{declareErrors.form}</Alert>}
                                <div className="sm:col-span-2">
                                    <Button type="submit">{t('hrms.holidays.declare')}</Button>
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

            <Modal open={calendarModal} onClose={closeCalendarModal} title={editingCalendar ? t('hrms.holidays.editCalendar') : t('hrms.holidays.newCalendar')}>
                <form onSubmit={saveCalendar} className="grid grid-cols-1 gap-3 px-6 py-5 sm:grid-cols-2">
                    <Input label={t('hrms.holidays.calName')} value={calendarForm.name} onChange={(e) => setCalendarForm({ ...calendarForm, name: e.target.value })} error={calendarErrors.name} />
                    <Input label={t('hrms.holidays.calCountry')} value={calendarForm.country} onChange={(e) => setCalendarForm({ ...calendarForm, country: e.target.value })} error={calendarErrors.country} />
                    <Input label={t('hrms.holidays.calDesc')} value={calendarForm.description} onChange={(e) => setCalendarForm({ ...calendarForm, description: e.target.value })} error={calendarErrors.description} className="sm:col-span-2" />
                    <Select label={t('hrms.holidays.calDefault')} value={String(calendarForm.is_default)} onChange={(e) => setCalendarForm({ ...calendarForm, is_default: e.target.value === 'true' })} error={calendarErrors.is_default}>
                        <option value="false">{t('hrms.common.no')}</option>
                        <option value="true">{t('hrms.common.yes')}</option>
                    </Select>
                    <Select label={t('hrms.holidays.calActive')} value={String(calendarForm.is_active)} onChange={(e) => setCalendarForm({ ...calendarForm, is_active: e.target.value === 'true' })} error={calendarErrors.is_active}>
                        <option value="true">{t('hrms.common.yes')}</option>
                        <option value="false">{t('hrms.common.no')}</option>
                    </Select>
                    {calendarErrors.form && <Alert>{calendarErrors.form}</Alert>}
                    <div className="flex justify-end gap-2 sm:col-span-2">
                        <Button type="button" variant="secondary" onClick={closeCalendarModal}>{t('hrms.common.cancel')}</Button>
                        <Button type="submit">{t('hrms.common.save')}</Button>
                    </div>
                </form>
            </Modal>

            <Modal open={holidayModal} onClose={closeHolidayModal} title={editingHoliday ? t('hrms.holidays.editHoliday') : t('hrms.holidays.newHoliday')}>
                <form onSubmit={saveHoliday} className="grid grid-cols-1 gap-3 px-6 py-5 sm:grid-cols-2">
                    <Input label={t('hrms.holidays.holName')} value={holidayForm.name} onChange={(e) => setHolidayForm({ ...holidayForm, name: e.target.value })} error={holidayErrors.name} />
                    <Input type="date" label={t('hrms.holidays.holDate')} value={holidayForm.date} onChange={(e) => setHolidayForm({ ...holidayForm, date: e.target.value })} error={holidayErrors.date} />
                    <Select label={t('hrms.holidays.holType')} value={holidayForm.type} onChange={(e) => setHolidayForm({ ...holidayForm, type: e.target.value })} error={holidayErrors.type}>
                        {HOLIDAY_TYPES.map((option) => (
                            <option key={option.value} value={option.value}>{t(`hrms.holidays.${option.key}`)}</option>
                        ))}
                    </Select>
                    <Select label={t('hrms.holidays.recurring')} value={String(holidayForm.is_recurring)} onChange={(e) => setHolidayForm({ ...holidayForm, is_recurring: e.target.value === 'true' })} error={holidayErrors.is_recurring}>
                        <option value="true">{t('hrms.common.yes')}</option>
                        <option value="false">{t('hrms.common.no')}</option>
                    </Select>
                    {holidayErrors.form && <Alert>{holidayErrors.form}</Alert>}
                    <div className="flex justify-end gap-2 sm:col-span-2">
                        <Button type="button" variant="secondary" onClick={closeHolidayModal}>{t('hrms.common.cancel')}</Button>
                        <Button type="submit">{t('hrms.common.save')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
