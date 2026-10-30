import { useCallback, useEffect, useMemo, useState } from 'react';
import ListSavedFilters from '../../components/ui/ListSavedFilters';
import { Link, useNavigate } from 'react-router-dom';
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
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { formatMinutes } from '../../utils/time';
import { formatTime } from '../../utils/format';
import i18n, { intlTag } from '../../i18n';
import AttendanceCalendar from '../../components/hrms/AttendanceCalendar';
import ClockInWidget from '../../components/hrms/ClockInWidget';

const STATUS_OPTIONS = [
    { value: '', key: 'statusAll' },
    { value: 'present', key: 'statusPresent' },
    { value: 'absent', key: 'statusAbsent' },
    { value: 'half_day', key: 'statusHalf' },
    { value: 'late', key: 'statusLate' },
    { value: 'leave', key: 'statusLeave' },
    { value: 'holiday', key: 'statusHoliday' },
    { value: 'week_off', key: 'statusWeekOff' },
];

function monthLabel(year, month) {
    return new Date(year, month - 1, 1).toLocaleDateString(intlTag(i18n.language), { month: 'long', year: 'numeric' });
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
    const { t } = useTranslation();
    usePageTitle(t('hrms.attendance.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can, user } = useAuth();
    const toast = useToast();

    const canViewOthers = can('permission:hrms.attendance.view');
    const canRegularize = can('permission:hrms.attendance.regularize');
    const canExport = can('permission:workspaces.manage');
    const canSettings = can('permission:hrms.attendance.settings');

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
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [settingsLoading, setSettingsLoading] = useState(false);
    const [savingSettings, setSavingSettings] = useState(false);
    const [settingsForm, setSettingsForm] = useState({
        auto_derive_from_work_logs: false,
        rounding_minutes: 15,
        ot_after_minutes: 480,
        allow_negative_ot: false,
        remote_clock_in_enabled: true,
        out_of_range_action: 'flag',
    });
    const [settingsErrors, setSettingsErrors] = useState({});

    async function openSettings() {
        setSettingsErrors({});
        setSettingsOpen(true);
        setSettingsLoading(true);
        try {
            const { data } = await api.get('/hrms/attendance/settings');
            const att = data.settings?.attendance ?? {};
            const rci = data.settings?.remote_clock_in ?? {};
            setSettingsForm({
                auto_derive_from_work_logs: Boolean(att.auto_derive_from_work_logs),
                rounding_minutes: att.rounding_minutes ?? 15,
                ot_after_minutes: att.ot_after_minutes ?? 480,
                allow_negative_ot: Boolean(att.allow_negative_ot),
                remote_clock_in_enabled: rci.enabled ?? true,
                out_of_range_action: rci.out_of_range_action ?? 'flag',
            });
        } catch (err) {
            toast.error(t('hrms.attendance.settingsFailed'));
        } finally {
            setSettingsLoading(false);
        }
    }

    async function saveSettings(e) {
        e.preventDefault();
        setSavingSettings(true);
        setSettingsErrors({});

        try {
            await api.put('/hrms/attendance/settings', {
                attendance: {
                    auto_derive_from_work_logs: settingsForm.auto_derive_from_work_logs,
                    rounding_minutes: Number(settingsForm.rounding_minutes),
                    ot_after_minutes: Number(settingsForm.ot_after_minutes),
                    allow_negative_ot: Boolean(settingsForm.allow_negative_ot),
                },
                remote_clock_in: {
                    enabled: Boolean(settingsForm.remote_clock_in_enabled),
                    out_of_range_action: settingsForm.out_of_range_action,
                },
            });
            toast.success(t('hrms.attendance.settingsSaved'));
            setSettingsOpen(false);
        } catch (err) {
            setSettingsErrors(fieldErrors(err));
        } finally {
            setSavingSettings(false);
        }
    }

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.attendance.pageTitle') }]);
    }, [setCrumbs, t]);

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

            setError(t('hrms.attendance.loadFailed'));
        });
    }, [params, employeeId, navigate, t]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!canViewOthers) return;

        api.get('/hrms/employees', { params: { per_page: 250 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name, email: e.user?.email, userId: e.user?.id ?? null }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // `RegularizationStoreRequest` → `RegularizationController::store`
    // always files against the CALLER's own employee row and stamps the day
    // from the payload, so a correction asked for while looking at somebody
    // else's calendar would silently correct the caller's own record on a
    // date that belongs to someone else. The button is therefore only an
    // offer when the grid on show is the caller's own ("Myself" = empty
    // `employeeId`, or the picked row resolving to the caller's user id).
    const viewingSelf =
        !employeeId ||
        (employees ?? []).some((e) => String(e.id) === String(employeeId) && e.userId === user?.id);

    function moveMonth(delta) {
        const next = shiftMonth(year, month, delta);

        setYear(next.year);
        setMonth(next.month);
        setSelected(null);
    }

    // Best-effort position for the geofence leg: a denied or slow lookup just
    // sends the punch without coordinates (the server decides what that means).
    function currentPosition() {
        if (!navigator.geolocation) return Promise.resolve(null);

        return new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
                () => resolve(null),
                { timeout: 5000, maximumAge: 60000 },
            );
        });
    }

    async function punch(direction, kind = 'work') {
        setPunching(true);

        try {
            const position = await currentPosition();
            const { data } = await api.post('/hrms/attendance/punch', { direction, kind, ...(position ?? {}) });

            toast.success(data.message);
            load();
        } catch (err) {
            const errors = err.response?.data?.errors;
            toast.error(errors?.location?.[0] ?? err.response?.data?.message ?? t('hrms.home.punchFailed'));
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
            toast.error(t('hrms.attendance.exportFailed'));
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

            toast.success(t('hrms.attendance.correctionSent'));
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
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.attendance.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {monthData ? `${monthData.employee?.name} · ${monthLabel(year, month)}` : '—'}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {canRegularize && (
                        <Link to="/hrms/attendance/approvals">
                            <Button variant="secondary">{t('hrms.attendance.reviewQueue')}</Button>
                        </Link>
                    )}
                    {canExport && (
                        <Button variant="secondary" onClick={exportCsv} disabled={exporting || !monthData}>
                            {exporting ? t('hrms.attendance.exporting') : t('hrms.attendance.exportCsv')}
                        </Button>
                    )}
                    {canSettings && (
                        <Button variant="secondary" onClick={openSettings}>
                            {t('hrms.attendance.settings')}
                        </Button>
                    )}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="flex flex-wrap items-center gap-2">
                {canViewOthers && (
                    <Select
                        aria-label={t('hrms.attendance.employee')}
                        value={employeeId}
                        onChange={(e) => { setEmployeeId(e.target.value); setSelected(null); }}
                        className="w-56"
                    >
                        <option value="">{t('hrms.attendance.myself')}</option>
                        {(employees ?? []).map((employee) => (
                            <option key={employee.id} value={employee.id}>
                                {employee.name}
                            </option>
                        ))}
                    </Select>
                )}
                <Button variant="secondary" onClick={() => moveMonth(-1)}>{t('hrms.attendance.prev')}</Button>
                <Button
                    variant="secondary"
                    onClick={() => { const current = new Date(); setYear(current.getFullYear()); setMonth(current.getMonth() + 1); setSelected(null); }}
                >
                    {t('hrms.attendance.thisMonth')}
                </Button>
                <Button variant="secondary" onClick={() => moveMonth(1)}>{t('hrms.attendance.next')}</Button>
                <Select aria-label={t('hrms.attendance.statusFilter')} value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-44">
                    {STATUS_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {t(`hrms.attendance.${option.key}`)}
                        </option>
                    ))}
                </Select>
                <ListSavedFilters page="hrms-attendance" value={{ status: statusFilter }} onApply={(saved) => setStatusFilter(saved.status || '')} />
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <Card className="xl:col-span-2" title={monthLabel(year, month)} dense>
                    {!monthData ? (
                        <ContentSkeleton size="md" />
                    ) : days.length === 0 ? (
                        <EmptyState title={t('hrms.attendance.emptyTitle')} description={t('hrms.attendance.emptyHint')} />
                    ) : (
                        <AttendanceCalendar year={year} month={month} days={days} selectedDate={selected?.date ?? null} onSelectDay={setSelected} />
                    )}

                    {statusFilter && (
                        <div className="mt-3 border-t border-gray-100 pt-3">
                            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">
                                {t('hrms.attendance.days', { count: matching.length })} · {t(`hrms.attendance.${STATUS_OPTIONS.find((o) => o.value === statusFilter)?.key}`)}
                            </p>
                            {matching.length === 0 ? (
                                <p className="text-sm text-gray-500">{t('hrms.attendance.nothingStatus')}</p>
                            ) : (
                                <ul className="divide-y divide-gray-100">
                                    {matching.map((day) => (
                                        <li key={day.date}>
                                            <button type="button" onClick={() => setSelected(day)} className="flex w-full items-center justify-between py-1.5 text-left text-sm hover:text-[var(--accent-soft-text)]">
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

                    <Card title={t('hrms.attendance.totals')} dense>
                        {!summary ? (
                            <ContentSkeleton size="sm" />
                        ) : (
                            <dl className="grid grid-cols-2 gap-2 text-sm">
                                <div><dt className="text-gray-500">{t('hrms.attendance.present')}</dt><dd className="text-lg font-semibold text-gray-900">{summary.present}</dd></div>
                                <div><dt className="text-gray-500">{t('hrms.attendance.absent')}</dt><dd className="text-lg font-semibold text-gray-900">{summary.absent}</dd></div>
                                <div><dt className="text-gray-500">{t('hrms.attendance.late')}</dt><dd className="text-lg font-semibold text-gray-900">{summary.late}</dd></div>
                                <div><dt className="text-gray-500">{t('hrms.attendance.halfDay')}</dt><dd className="text-lg font-semibold text-gray-900">{summary.half_day}</dd></div>
                                <div><dt className="text-gray-500">{t('hrms.attendance.worked')}</dt><dd className="text-lg font-semibold text-gray-900">{formatMinutes(summary.worked_minutes)}</dd></div>
                                <div><dt className="text-gray-500">{t('hrms.attendance.overtime')}</dt><dd className="text-lg font-semibold text-gray-900">{formatMinutes(summary.overtime_minutes)}</dd></div>
                            </dl>
                        )}
                    </Card>

                    {selected && (
                        <Card title={selected.date} dense>
                            <dl className="space-y-1.5 text-sm">
                                <div className="flex justify-between"><dt className="text-gray-500">{t('hrms.attendance.dayStatus')}</dt><dd className="font-medium text-gray-900">{selected.status_label}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">{t('hrms.attendance.dayIn')}</dt><dd className="text-gray-900">{selected.first_in_at ? formatTime(selected.first_in_at) : '—'}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">{t('hrms.attendance.dayOut')}</dt><dd className="text-gray-900">{selected.last_out_at ? formatTime(selected.last_out_at) : '—'}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">{t('hrms.attendance.dayWorked')}</dt><dd className="text-gray-900">{formatMinutes(selected.worked_minutes)}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">{t('hrms.attendance.lateBy')}</dt><dd className="text-gray-900">{formatMinutes(selected.late_by_minutes)}</dd></div>
                                <div className="flex justify-between"><dt className="text-gray-500">{t('hrms.attendance.dayOt')}</dt><dd className="text-gray-900">{formatMinutes(selected.overtime_minutes)}</dd></div>
                                {selected.is_regularized && <p className="pt-1 text-xs text-gray-500">{t('hrms.attendance.regularized')}</p>}
                            </dl>
                            <div className="mt-3">
                                {viewingSelf ? (
                                    <Button variant="secondary" onClick={() => openCorrection(selected)}>{t('hrms.attendance.requestCorrection')}</Button>
                                ) : (
                                    <p className="text-xs text-gray-400">
                                        {t('hrms.attendance.correctionOthers')}
                                    </p>
                                )}
                            </div>
                        </Card>
                    )}
                </div>
            </div>

            <Modal open={correcting} onClose={() => setCorrecting(false)} title={t('hrms.attendance.correctTitle', { date: selected?.date ?? '' })}>
                <form onSubmit={submitCorrection} className="space-y-3 px-6 py-5">
                    <p className="text-sm text-gray-500">
                        {t('hrms.attendance.correctIntro')}
                    </p>
                    <div className="grid grid-cols-2 gap-3">
                        <Input
                            type="time"
                            label={t('hrms.attendance.correctIn')}
                            value={correction.firstIn}
                            onChange={(e) => setCorrection((c) => ({ ...c, firstIn: e.target.value }))}
                            error={correctionErrors.requested_first_in_at}
                        />
                        <Input
                            type="time"
                            label={t('hrms.attendance.correctOut')}
                            value={correction.lastOut}
                            onChange={(e) => setCorrection((c) => ({ ...c, lastOut: e.target.value }))}
                            error={correctionErrors.requested_punch_at}
                        />
                    </div>
                    <Input
                        label={t('hrms.attendance.reason')}
                        value={correction.reason}
                        onChange={(e) => setCorrection((c) => ({ ...c, reason: e.target.value }))}
                        error={correctionErrors.reason}
                        placeholder={t('hrms.attendance.reasonPlaceholder')}
                    />
                    {correctionErrors.form && <Alert>{correctionErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setCorrecting(false)}>{t('hrms.attendance.cancel')}</Button>
                        <Button type="submit">{t('hrms.attendance.sendRequest')}</Button>
                    </div>
                </form>
            </Modal>

            <Modal
                open={settingsOpen}
                onClose={() => setSettingsOpen(false)}
                title={t('hrms.attendance.settingsTitle')}
            >
                {settingsLoading ? (
                    <div className="flex justify-center px-6 py-8">
                        <ContentSkeleton size="sm" />
                    </div>
                ) : (
                    <form onSubmit={saveSettings} className="space-y-4 px-6 py-5">
                        <div className="rounded-lg border border-gray-200 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-800/40">
                            <label className="flex items-start gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={settingsForm.auto_derive_from_work_logs}
                                    onChange={(e) =>
                                        setSettingsForm((s) => ({
                                            ...s,
                                            auto_derive_from_work_logs: e.target.checked,
                                        }))
                                    }
                                    className="mt-1 h-4 w-4 rounded border-gray-300 accent-[var(--accent)]"
                                />
                                <div>
                                    <span className="text-sm font-semibold text-gray-900 dark:text-blue-100">
                                        {t('hrms.attendance.derive')}
                                    </span>
                                    <p className="mt-0.5 text-xs text-gray-500 dark:text-blue-400">
                                        {t('hrms.attendance.deriveHint')}
                                    </p>
                                </div>
                            </label>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <Input
                                type="number"
                                label={t('hrms.attendance.rounding')}
                                min={1}
                                max={60}
                                value={settingsForm.rounding_minutes}
                                onChange={(e) =>
                                    setSettingsForm((s) => ({ ...s, rounding_minutes: e.target.value }))
                                }
                                error={settingsErrors['attendance.rounding_minutes']}
                            />
                            <Input
                                type="number"
                                label={t('hrms.attendance.otThreshold')}
                                min={0}
                                max={1440}
                                value={settingsForm.ot_after_minutes}
                                onChange={(e) =>
                                    setSettingsForm((s) => ({ ...s, ot_after_minutes: e.target.value }))
                                }
                                error={settingsErrors['attendance.ot_after_minutes']}
                            />
                        </div>

                        <div className="space-y-3 pt-2">
                            <label className="flex items-center gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={settingsForm.allow_negative_ot}
                                    onChange={(e) =>
                                        setSettingsForm((s) => ({
                                            ...s,
                                            allow_negative_ot: e.target.checked,
                                        }))
                                    }
                                    className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]"
                                />
                                <span className="text-sm text-gray-700 dark:text-blue-300">
                                    {t('hrms.attendance.allowNegative')}
                                </span>
                            </label>

                            <label className="flex items-center gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={settingsForm.remote_clock_in_enabled}
                                    onChange={(e) =>
                                        setSettingsForm((s) => ({
                                            ...s,
                                            remote_clock_in_enabled: e.target.checked,
                                        }))
                                    }
                                    className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]"
                                />
                                <span className="text-sm text-gray-700 dark:text-blue-300">
                                    {t('hrms.attendance.remoteEnabled')}
                                </span>
                            </label>

                            <Select
                                label={t('hrms.attendance.rangeAction')}
                                value={settingsForm.out_of_range_action}
                                onChange={(e) => setSettingsForm((current) => ({ ...current, out_of_range_action: e.target.value }))}
                                error={settingsErrors['remote_clock_in.out_of_range_action']}
                            >
                                <option value="flag">{t('hrms.attendance.rangeFlag')}</option>
                                <option value="block">{t('hrms.attendance.rangeBlock')}</option>
                            </Select>
                        </div>

                        {settingsErrors.form && <Alert>{settingsErrors.form}</Alert>}

                        <div className="flex justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setSettingsOpen(false)}
                                disabled={savingSettings}
                            >
                                {t('hrms.attendance.cancel')}
                            </Button>
                            <Button type="submit" disabled={savingSettings}>
                                {savingSettings ? t('hrms.attendance.saving') : t('hrms.attendance.saveSettings')}
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>
        </div>
    );
}
