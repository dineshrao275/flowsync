import { useCallback, useEffect, useState } from 'react';
import UserChip from '../components/ui/UserChip';
import TaskBadge from '../components/ui/TaskBadge';
import { useTranslation } from 'react-i18next';
import { CaretLeft, CaretRight } from '@phosphor-icons/react';
import api, { fieldErrors } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { formatMinutes } from '../utils/time';
import { formatDateOnly } from '../utils/format';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import Alert from '../components/ui/Alert';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Icon from '../components/ui/Icon';
import Input from '../components/ui/Input';
import { Table, Th, Td } from '../components/ui/Table';

const TONE = { open: 'neutral', submitted: 'info', approved: 'success', rejected: 'danger' };

function shiftWeek(isoDate, weeks) {
    const d = new Date(`${isoDate}T00:00:00`);
    d.setDate(d.getDate() + weeks * 7);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function MyWeek() {
    const { t } = useTranslation();
    const toast = useToast();
    const [week, setWeek] = useState(null);
    const [sheet, setSheet] = useState(null);
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    const load = useCallback((date) => {
        api.get('/timesheets/week', { params: date ? { week: date } : {} })
            .then(({ data }) => { setSheet(data.timesheet); setWeek(data.timesheet.week_start); })
            .catch(() => setError(t('tms.timesheets.loadFailed')));
    }, [t]);

    useEffect(() => load(), [load]);

    async function submit() {
        setSubmitting(true);
        setError(null);
        try {
            const { data } = await api.post('/timesheets/submit', { week });
            setSheet(data.timesheet);
            toast.success(t('tms.timesheets.submitted'));
        } catch (err) {
            setError(fieldErrors(err).form || t('tms.timesheets.submitFailed'));
        } finally {
            setSubmitting(false);
        }
    }

    if (!sheet) return (error ? <Alert>{error}</Alert> : <ContentSkeleton size="md" />);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Button size="icon" variant="secondary" aria-label={t('tms.timesheets.previous')} onClick={() => load(shiftWeek(week, -1))}><Icon as={CaretLeft} size="sm" /></Button>
                    <p className="min-w-48 text-center text-sm font-semibold text-gray-900">
                        {formatDateOnly(sheet.week_start)} – {formatDateOnly(sheet.week_end)}
                    </p>
                    <Button size="icon" variant="secondary" aria-label={t('tms.timesheets.next')} onClick={() => load(shiftWeek(week, 1))}><Icon as={CaretRight} size="sm" /></Button>
                </div>
                <div className="flex items-center gap-3">
                    <Badge tone={TONE[sheet.status]}>{t(`tms.timesheets.status.${sheet.status}`)}</Badge>
                    {!sheet.locked && (
                        <Button onClick={submit} loading={submitting} disabled={sheet.total_minutes === 0}>{t('tms.timesheets.submit')}</Button>
                    )}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}
            {sheet.status === 'rejected' && sheet.decision_note && <Alert type="warning">{t('tms.timesheets.sentBack', { note: sheet.decision_note })}</Alert>}

            <div className="grid grid-cols-3 gap-3 text-center">
                <div className="rounded-lg border border-gray-100 px-3 py-2"><p className="text-lg font-bold">{formatMinutes(sheet.total_minutes)}</p><p className="text-xs text-gray-500">{t('tms.timesheets.total')}</p></div>
                <div className="rounded-lg border border-gray-100 px-3 py-2"><p className="text-lg font-bold">{formatMinutes(sheet.billable_minutes)}</p><p className="text-xs text-gray-500">{t('tms.timesheets.billable')}</p></div>
                <div className="rounded-lg border border-gray-100 px-3 py-2"><p className="text-lg font-bold">{formatMinutes(sheet.total_minutes - sheet.billable_minutes)}</p><p className="text-xs text-gray-500">{t('tms.timesheets.nonBillable')}</p></div>
            </div>

            {sheet.entries.length === 0 ? (
                <p className="py-8 text-center text-sm text-gray-500">{t('tms.timesheets.empty')}</p>
            ) : (
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('tms.timesheets.task')}</Th>
                            <Th>{t('tms.timesheets.project')}</Th>
                            <Th align="right">{t('tms.timesheets.total')}</Th>
                            <Th align="right">{t('tms.timesheets.billable')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {sheet.entries.map((e) => (
                            <tr key={e.task_id}>
                                <Td>
                                    <div className="flex items-center gap-2">
                                        <TaskBadge taskKey={e.task_key} taskId={e.task_id} projectId={e.project_id} title={e.task_title} />
                                        <span className="font-medium text-gray-900">{e.task_title}</span>
                                    </div>
                                </Td>
                                <Td>{e.project}</Td>
                                <Td align="right">{formatMinutes(e.minutes)}</Td>
                                <Td align="right">{formatMinutes(e.billable_minutes)}</Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </div>
    );
}

function Approvals() {
    const { t } = useTranslation();
    const toast = useToast();
    const [rows, setRows] = useState(undefined);
    const [note, setNote] = useState({});
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/timesheets/pending').then(({ data }) => setRows(data.timesheets)).catch(() => setRows([]));
    }, []);

    useEffect(load, [load]);

    async function decide(row, approve) {
        setError(null);
        try {
            await api.post(`/timesheets/${row.id}/${approve ? 'approve' : 'reject'}`, approve ? {} : { note: note[row.id] || '' });
            toast.success(approve ? t('tms.timesheets.approved') : t('tms.timesheets.rejected'));
            load();
        } catch (err) {
            setError(fieldErrors(err).note || fieldErrors(err).form || t('tms.timesheets.decideFailed'));
        }
    }

    if (rows === undefined) return <ContentSkeleton size="md" />;
    if (rows.length === 0) return <p className="py-8 text-center text-sm text-gray-500">{t('tms.timesheets.nothingPending')}</p>;

    return (
        <div className="space-y-3">
            {error && <Alert>{error}</Alert>}
            {rows.map((row) => (
                <div key={row.id} className="flex flex-wrap items-end justify-between gap-3 rounded-lg border border-gray-200 p-4">
                    <div>
                        <p className="font-medium text-gray-900"><UserChip user={row.user} size="sm" /></p>
                        <p className="text-sm text-gray-500">
                            {t('tms.timesheets.weekOf', { date: formatDateOnly(row.week_start) })} · {formatMinutes(row.total_minutes)} ({formatMinutes(row.billable_minutes)} {t('tms.timesheets.billableShort')})
                        </p>
                    </div>
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="w-56"><Input label={t('tms.timesheets.noteLabel')} compact value={note[row.id] || ''} onChange={(e) => setNote((n) => ({ ...n, [row.id]: e.target.value }))} /></div>
                        <Button variant="secondary" onClick={() => decide(row, false)}>{t('tms.timesheets.sendBack')}</Button>
                        <Button onClick={() => decide(row, true)}>{t('tms.timesheets.approve')}</Button>
                    </div>
                </div>
            ))}
        </div>
    );
}

export default function Timesheets() {
    const { t } = useTranslation();
    usePageTitle(t('tms.timesheets.title'));
    const { can } = useAuth();
    const [tab, setTab] = useState('mine');
    const approver = can('workspaces.manage') || can('workspaces.view');

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('tms.timesheets.title')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('tms.timesheets.subtitle')}</p>
            </div>
            {approver && (
                <div role="tablist" className="flex gap-1 border-b border-gray-200">
                    {['mine', 'approvals'].map((k) => (
                        <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
                            className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium ${tab === k ? 'border-[var(--accent)] text-[var(--accent)]' : 'border-transparent text-gray-500 hover:text-gray-800'}`}>
                            {t(`tms.timesheets.tab.${k}`)}
                        </button>
                    ))}
                </div>
            )}
            <Card>{tab === 'mine' ? <MyWeek /> : <Approvals />}</Card>
        </div>
    );
}
