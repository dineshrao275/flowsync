import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Stop } from '@phosphor-icons/react';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import Icon from '../ui/Icon';

export const TIMER_EVENT = 'flowsync:timer-changed';

function clock(totalSeconds) {
    const s = Math.max(0, totalSeconds);
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
}

/** The running timer, always visible in the top bar: task, elapsed time, and a stop button. */
export default function TimerPill() {
    const { t } = useTranslation();
    const { hasModule, user } = useAuth();
    const toast = useToast();
    const [timer, setTimer] = useState(null);
    const [now, setNow] = useState(() => Date.now());
    const enabled = Boolean(user) && hasModule('time_tracking');

    const load = useCallback(() => {
        if (!enabled) return;
        api.get('/time/timer').then(({ data }) => setTimer(data.timer)).catch(() => setTimer(null));
    }, [enabled]);

    useEffect(() => {
        load();
        window.addEventListener(TIMER_EVENT, load);
        return () => window.removeEventListener(TIMER_EVENT, load);
    }, [load]);

    useEffect(() => {
        if (!timer) return undefined;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [timer]);

    async function stop() {
        try {
            await api.post('/time/timer/stop');
            setTimer(null);
            toast.success(t('tms.timer.stopped'));
            window.dispatchEvent(new Event('flowsync:worklogs-changed'));
        } catch {
            toast.error(t('tms.timer.stopFailed'));
        }
    }

    if (!timer) return null;

    const elapsed = Math.floor((now - new Date(timer.started_at).getTime()) / 1000);

    return (
        <div className="flex items-center gap-2 rounded-full bg-[var(--accent-soft)] py-1 pl-3 pr-1 text-sm text-[var(--accent-soft-text)]" role="status" aria-label={t('tms.timer.running', { key: timer.task_key })}>
            <span className="h-2 w-2 animate-pulse rounded-full bg-red-500" aria-hidden="true" />
            <Link to={`/projects/${timer.project_id}?tab=tasks&task=${timer.task_key}&section=time`} className="max-w-32 truncate font-medium hover:underline">
                {timer.task_key}
            </Link>
            <span className="font-mono tabular-nums">{clock(elapsed)}</span>
            <button type="button" onClick={stop} aria-label={t('tms.timer.stop')} className="rounded-full p-1.5 hover:bg-black/10">
                <Icon as={Stop} size="sm" weight="fill" />
            </button>
        </div>
    );
}
