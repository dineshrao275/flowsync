import { useCallback, useEffect, useRef, useState } from 'react';
import api from '../services/api';

export const DEFAULT_BOARD_PREFS = {
    hidden: [],
    collapsed: [],
    width: 'default',
    density: 'comfortable',
    hide_empty: false,
};

/**
 * The caller's personal board view for one project, stored server-side so it
 * follows them across devices. Updates apply immediately; the save is
 * debounced and a failed save leaves the local value in place (a view
 * preference is not worth interrupting the user for).
 */
export default function useBoardPreferences(projectId, enabled = true) {
    const [prefs, setPrefs] = useState(DEFAULT_BOARD_PREFS);
    const timer = useRef(null);
    const pending = useRef({});

    useEffect(() => {
        if (!enabled || !projectId) return undefined;
        let active = true;
        api.get(`/projects/${projectId}/board-preferences`)
            .then(({ data }) => {
                if (active) setPrefs({ ...DEFAULT_BOARD_PREFS, ...data.preferences });
            })
            .catch(() => {});

        return () => {
            active = false;
        };
    }, [projectId, enabled]);

    useEffect(() => () => clearTimeout(timer.current), []);

    const update = useCallback((patch) => {
        setPrefs((cur) => ({ ...cur, ...patch }));
        pending.current = { ...pending.current, ...patch };
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            const body = pending.current;
            pending.current = {};
            api.put(`/projects/${projectId}/board-preferences`, body).catch(() => {});
        }, 400);
    }, [projectId]);

    return [prefs, update];
}
