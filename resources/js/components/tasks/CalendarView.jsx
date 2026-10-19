import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CaretLeft, CaretRight } from '@phosphor-icons/react';
import api from '../../services/api';
import i18n, { intlTag } from '../../i18n';
import { useToast } from '../../context/ToastContext';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import Spinner from '../ui/Spinner';

const pad = (n) => String(n).padStart(2, '0');
const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const MAX_PAGES = 5;

/** Monday-first grid of the 6 weeks covering the month of `anchor`. */
export function monthGrid(anchor) {
    const first = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7;
    const start = new Date(first);
    start.setDate(first.getDate() - offset);

    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        return d;
    });
}

/**
 * Due-date calendar for one project. Chips show the task key and title; click opens the task and,
 * for people who may edit, dragging a chip to another day reschedules its due date.
 */
export default function CalendarView({ projectId, filters, canEdit, onOpen }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [anchor, setAnchor] = useState(() => new Date());
    const [tasks, setTasks] = useState(null);
    const [dragging, setDragging] = useState(null);
    const days = useMemo(() => monthGrid(anchor), [anchor]);
    const tag = intlTag(i18n.language);

    const load = useCallback(async () => {
        const params = { ...filters, view: 'list', per_page: 100, due_from: iso(days[0]), due_to: iso(days[41]) };
        Object.keys(params).forEach((k) => (params[k] == null || params[k] === '') && delete params[k]);
        let all = [];
        for (let page = 1; page <= MAX_PAGES; page++) {
            // eslint-disable-next-line no-await-in-loop
            const { data } = await api.get(`/projects/${projectId}/tasks`, { params: { ...params, page } });
            all = all.concat(data.tasks);
            if (page >= data.pagination.last_page) break;
        }
        setTasks(all);
    }, [projectId, filters, days]);

    useEffect(() => {
        setTasks(null);
        load().catch(() => setTasks([]));
    }, [load]);

    const byDay = useMemo(() => {
        const map = {};
        (tasks || []).forEach((task) => {
            if (!task.due_date) return;
            const key = task.due_date.slice(0, 10);
            (map[key] ||= []).push(task);
        });
        return map;
    }, [tasks]);

    async function drop(day) {
        const task = dragging;
        setDragging(null);
        if (!task || iso(day) === task.due_date?.slice(0, 10)) return;
        const before = tasks;
        setTasks((list) => list.map((x) => (x.id === task.id ? { ...x, due_date: iso(day) } : x)));
        try {
            await api.put(`/projects/${projectId}/tasks/${task.id}`, { due_date: iso(day) });
        } catch {
            setTasks(before);
            toast.error(t('tms.calendar.rescheduleFailed'));
        }
    }

    const weekdays = days.slice(0, 7).map((d) => d.toLocaleDateString(tag, { weekday: 'short' }));
    const today = iso(new Date());

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <p className="text-base font-semibold text-gray-900" aria-live="polite">
                    {anchor.toLocaleDateString(tag, { month: 'long', year: 'numeric' })}
                </p>
                <div className="flex items-center gap-2">
                    {tasks === null && <Spinner className="h-4 w-4" />}
                    <Button size="icon" variant="secondary" aria-label={t('tms.calendar.previous')} onClick={() => setAnchor(new Date(anchor.getFullYear(), anchor.getMonth() - 1, 1))}><Icon as={CaretLeft} size="sm" /></Button>
                    <Button size="sm" variant="secondary" onClick={() => setAnchor(new Date())}>{t('tms.calendar.today')}</Button>
                    <Button size="icon" variant="secondary" aria-label={t('tms.calendar.next')} onClick={() => setAnchor(new Date(anchor.getFullYear(), anchor.getMonth() + 1, 1))}><Icon as={CaretRight} size="sm" /></Button>
                </div>
            </div>

            <div className="overflow-x-auto">
                <div className="grid min-w-[720px] grid-cols-7 overflow-hidden rounded-xl border border-line text-sm" role="grid" aria-label={t('tms.calendar.label')}>
                    {weekdays.map((w) => (
                        <div key={w} role="columnheader" className="border-b border-line bg-surface-sunken px-2 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">{w}</div>
                    ))}
                    {days.map((d) => {
                        const key = iso(d);
                        const inMonth = d.getMonth() === anchor.getMonth();
                        const list = byDay[key] || [];
                        return (
                            <div
                                key={key}
                                role="gridcell"
                                onDragOver={(e) => canEdit && e.preventDefault()}
                                onDrop={() => canEdit && drop(d)}
                                className={`min-h-28 border-b border-r border-line p-1.5 ${inMonth ? 'bg-white' : 'bg-surface-sunken/60'}`}
                            >
                                <p className={`mb-1 text-xs ${key === today ? 'inline-block rounded-full bg-[var(--accent)] px-1.5 font-semibold text-[var(--accent-contrast)]' : inMonth ? 'text-gray-700' : 'text-gray-400'}`}>{d.getDate()}</p>
                                <ul className="space-y-1">
                                    {list.slice(0, 4).map((task) => (
                                        <li key={task.id}>
                                            <button
                                                type="button"
                                                draggable={canEdit}
                                                onDragStart={() => setDragging(task)}
                                                onClick={() => onOpen(task)}
                                                title={`${task.key} · ${task.title}`}
                                                className={`w-full truncate rounded px-1.5 py-0.5 text-left text-xs ${task.completed_at ? 'bg-success-soft text-success line-through' : 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'} hover:brightness-95`}
                                            >
                                                <span className="font-semibold">{task.key}</span> {task.title}
                                            </button>
                                        </li>
                                    ))}
                                    {list.length > 4 && <li className="px-1 text-xs text-gray-500">{t('tms.calendar.more', { count: list.length - 4 })}</li>}
                                </ul>
                            </div>
                        );
                    })}
                </div>
            </div>
            {tasks && tasks.length === 0 && <p className="text-center text-sm text-gray-500">{t('tms.calendar.empty')}</p>}
        </div>
    );
}
