import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CaretLeft, CaretRight } from '@phosphor-icons/react';
import api from '../../services/api';
import i18n, { intlTag } from '../../i18n';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import Spinner from '../ui/Spinner';

const DAY = 24 * 60 * 60 * 1000;
const WEEKS = 10;
const MAX_PAGES = 5;
const ROW = 32;

const startOfWeek = (d) => {
    const x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
    return x;
};
const asDate = (s) => (s ? new Date(`${s.slice(0, 10)}T00:00:00`) : null);

/**
 * Where each task's bar starts/ends inside the visible window, as percentages (or null when it lies
 * outside). A task with only one date is drawn as a one-day marker on that date.
 */
export function barFor(task, from, days) {
    const start = asDate(task.start_date) || asDate(task.due_date);
    const end = asDate(task.due_date) || start;
    if (!start) return null;

    const a = Math.round((start - from) / DAY);
    const b = Math.round((end - from) / DAY) + 1;
    if (b <= 0 || a >= days) return null;

    const left = Math.max(0, a);
    const right = Math.min(days, b);

    return { left: (left / days) * 100, width: Math.max(((right - left) / days) * 100, 100 / days), clippedLeft: a < 0, clippedRight: b > days };
}

/** Read-only roadmap: tasks with dates as bars on a weekly scale, grouped by version (or "No version"). */
export default function TimelineView({ projectId, filters, onOpen }) {
    const { t } = useTranslation();
    const [from, setFrom] = useState(() => startOfWeek(new Date()));
    const [tasks, setTasks] = useState(null);
    const days = WEEKS * 7;
    const to = useMemo(() => new Date(from.getTime() + (days - 1) * DAY), [from, days]);
    const tag = intlTag(i18n.language);

    const load = useCallback(async () => {
        const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        // A task is on screen if it ends after the window starts and starts before it ends: fetch by due
        // date (the filterable column) and widen to the right by the longest bar we are willing to chase.
        const params = { ...filters, view: 'list', per_page: 100, due_from: fmt(from), due_to: fmt(new Date(to.getTime() + 60 * DAY)) };
        Object.keys(params).forEach((k) => (params[k] == null || params[k] === '') && delete params[k]);
        let all = [];
        for (let page = 1; page <= MAX_PAGES; page++) {
            // eslint-disable-next-line no-await-in-loop
            const { data } = await api.get(`/projects/${projectId}/tasks`, { params: { ...params, page } });
            all = all.concat(data.tasks);
            if (page >= data.pagination.last_page) break;
        }
        setTasks(all);
    }, [projectId, filters, from, to]);

    useEffect(() => {
        setTasks(null);
        load().catch(() => setTasks([]));
    }, [load]);

    const groups = useMemo(() => {
        const map = new Map();
        (tasks || []).forEach((task) => {
            const bar = barFor(task, from, days);
            if (!bar) return;
            const key = task.version?.name || '';
            if (!map.has(key)) map.set(key, []);
            map.get(key).push({ task, bar });
        });
        return [...map.entries()].sort(([a], [b]) => (a === '' ? 1 : b === '' ? -1 : a.localeCompare(b)));
    }, [tasks, from, days]);

    const weeks = Array.from({ length: WEEKS }, (_, i) => new Date(from.getTime() + i * 7 * DAY));
    const todayPct = ((Date.now() - from.getTime()) / DAY / days) * 100;

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <p className="text-base font-semibold text-gray-900">
                    {from.toLocaleDateString(tag, { month: 'short', day: 'numeric' })} – {to.toLocaleDateString(tag, { month: 'short', day: 'numeric', year: 'numeric' })}
                </p>
                <div className="flex items-center gap-2">
                    {tasks === null && <Spinner className="h-4 w-4" />}
                    <Button size="icon" variant="secondary" aria-label={t('tms.timeline.previous')} onClick={() => setFrom(new Date(from.getTime() - 4 * 7 * DAY))}><Icon as={CaretLeft} size="sm" /></Button>
                    <Button size="sm" variant="secondary" onClick={() => setFrom(startOfWeek(new Date()))}>{t('tms.calendar.today')}</Button>
                    <Button size="icon" variant="secondary" aria-label={t('tms.timeline.next')} onClick={() => setFrom(new Date(from.getTime() + 4 * 7 * DAY))}><Icon as={CaretRight} size="sm" /></Button>
                </div>
            </div>

            <div className="overflow-x-auto rounded-xl border border-line bg-white">
                <div className="min-w-[760px]">
                    <div className="grid border-b border-line bg-surface-sunken text-xs font-semibold text-gray-500" style={{ gridTemplateColumns: `repeat(${WEEKS}, 1fr)` }}>
                        {weeks.map((w) => (
                            <div key={w.toISOString()} className="border-r border-line px-2 py-1.5">{w.toLocaleDateString(tag, { month: 'short', day: 'numeric' })}</div>
                        ))}
                    </div>
                    {groups.length === 0 && tasks !== null && (
                        <p className="p-8 text-center text-sm text-gray-500">{t('tms.timeline.empty')}</p>
                    )}
                    {groups.map(([version, rows]) => (
                        <section key={version || 'none'} aria-label={version || t('tms.timeline.noVersion')}>
                            <h4 className="bg-gray-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500">{version || t('tms.timeline.noVersion')}</h4>
                            <div className="relative" style={{ height: rows.length * ROW }}>
                                {todayPct >= 0 && todayPct <= 100 && <div className="absolute inset-y-0 w-px bg-red-400" style={{ left: `${todayPct}%` }} aria-hidden="true" />}
                                {rows.map(({ task, bar }, i) => (
                                    <button
                                        key={task.id}
                                        type="button"
                                        onClick={() => onOpen(task)}
                                        title={`${task.key} · ${task.title}`}
                                        className={`absolute flex items-center truncate px-2 text-xs ${task.completed_at ? 'bg-success-soft text-success' : 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'} hover:brightness-95 ${bar.clippedLeft ? 'rounded-l-none' : 'rounded-l'} ${bar.clippedRight ? 'rounded-r-none' : 'rounded-r'}`}
                                        style={{ left: `${bar.left}%`, width: `${bar.width}%`, top: i * ROW + 4, height: ROW - 8 }}
                                    >
                                        <span className="mr-1 font-semibold">{task.key}</span>
                                        <span className="truncate">{task.title}</span>
                                    </button>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            </div>
        </div>
    );
}
