import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import api from '../services/api';
import { useAuth } from '../context/AuthContext';

const DevelopmentSummaryContext = createContext(null);

/**
 * Per-task pull request badges for a project's board. One cheap request per board refresh, only for
 * callers whose plan has the `git` module and who hold `git.view`; a failure just means no badges.
 */
export function DevelopmentSummaryProvider({ projectId, refreshKey, children }) {
    const { hasModule, can } = useAuth();
    const enabled = hasModule('git') && can('git.view');
    const [summary, setSummary] = useState(null);

    useEffect(() => {
        if (!enabled) return undefined;
        const controller = new AbortController();
        api.get(`/projects/${projectId}/development/summary`, { signal: controller.signal })
            .then(({ data }) => setSummary(data))
            .catch(() => {});
        return () => controller.abort();
    }, [enabled, projectId, refreshKey]);

    const value = useMemo(() => (enabled ? summary : null), [enabled, summary]);

    return <DevelopmentSummaryContext.Provider value={value}>{children}</DevelopmentSummaryContext.Provider>;
}

/** `{ pull_requests, open, checks_state }` for a task, or null when it has none (or the feature is off). */
export function useTaskDevelopment(taskId) {
    return useContext(DevelopmentSummaryContext)?.tasks?.[taskId] ?? null;
}

/** Linked repositories with their latest PR/commit (project overview). */
export function useDevelopmentRepositories() {
    return useContext(DevelopmentSummaryContext)?.repositories ?? null;
}
