import { useCallback, useEffect, useState } from 'react';
import UserChip from '../ui/UserChip';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import { formatMinutes } from '../../utils/time';
import Card from '../ui/Card';
import Alert from '../ui/Alert';
import ContentSkeleton from '../ui/ContentSkeleton';
import Badge from '../ui/Badge';
import { Table, Th, Td } from '../ui/Table';

const WEEK_OPTIONS = [1, 2, 4, 8];

/** Utilization as a bar that turns red past 100% of capacity. */
export function UtilizationBar({ value, label }) {
    const pct = value == null ? 100 : Math.min(value, 1.5) * 100;
    const over = value == null || value > 1;
    return (
        <div className="flex items-center gap-2" role="img" aria-label={label}>
            <div className="h-2 w-28 overflow-hidden rounded-full bg-gray-100">
                <div className={`h-full ${over ? 'bg-red-500' : value > 0.85 ? 'bg-amber-400' : 'bg-emerald-500'}`} style={{ width: `${Math.min(pct, 100)}%` }} />
            </div>
            <span className={`text-xs tabular-nums ${over ? 'font-semibold text-red-600' : 'text-gray-600'}`}>{value == null ? '—' : `${Math.round(value * 100)}%`}</span>
        </div>
    );
}

/** Capacity vs planned work per member (remaining estimates of open tasks due in the window). */
export default function WorkloadPanel({ projectId }) {
    const { t } = useTranslation();
    const [weeks, setWeeks] = useState(4);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        setData(null);
        setError(null);
        const start = new Date();
        start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
        const end = new Date(start);
        end.setDate(start.getDate() + weeks * 7 - 1);
        const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        api.get(`/projects/${projectId}/workload`, { params: { from: fmt(start), to: fmt(end) } })
            .then(({ data: res }) => setData(res.workload))
            .catch(() => setError(t('tms.workload.loadFailed')));
    }, [projectId, weeks, t]);

    useEffect(load, [load]);

    return (
        <Card title={t('tms.workload.title')} subtitle={t('tms.workload.subtitle')}>
            <div className="mb-4 flex items-center gap-2">
                <label htmlFor="workload-weeks" className="text-sm text-gray-600">{t('tms.workload.window')}</label>
                <select id="workload-weeks" value={weeks} onChange={(e) => setWeeks(Number(e.target.value))} className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-sm">
                    {WEEK_OPTIONS.map((w) => <option key={w} value={w}>{t('tms.workload.weeks', { count: w })}</option>)}
                </select>
            </div>
            {error && <Alert>{error}</Alert>}
            {!data && !error && <ContentSkeleton size="md" />}
            {data && (
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('tms.workload.member')}</Th>
                            <Th align="right">{t('tms.workload.capacity')}</Th>
                            <Th align="right">{t('tms.workload.planned')}</Th>
                            <Th>{t('tms.workload.utilization')}</Th>
                            <Th>{t('tms.workload.notes')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.members.map((m) => (
                            <tr key={m.user.id}>
                                <Td><UserChip user={m.user} size="sm" /></Td>
                                <Td align="right">{formatMinutes(m.capacity_minutes)}{m.leave_days > 0 && <span className="ml-1 text-xs text-gray-500">({t('tms.workload.leave', { count: m.leave_days })})</span>}</Td>
                                <Td align="right">{formatMinutes(m.planned_minutes)}</Td>
                                <Td><UtilizationBar value={m.utilization} label={t('tms.workload.utilizationLabel', { name: m.user.name })} /></Td>
                                <Td>
                                    <div className="flex flex-wrap gap-1">
                                        {m.overallocated && <Badge tone="danger">{t('tms.workload.over')}</Badge>}
                                        {m.unestimated_tasks > 0 && <Badge tone="warning">{t('tms.workload.unestimated', { count: m.unestimated_tasks })}</Badge>}
                                        {m.unscheduled_tasks > 0 && <Badge tone="neutral">{t('tms.workload.unscheduled', { count: m.unscheduled_tasks })}</Badge>}
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}
