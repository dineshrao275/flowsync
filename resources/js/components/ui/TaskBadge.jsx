import { memo } from 'react';
import { Link } from 'react-router-dom';
import { taskUrl } from '../../utils/deepLinks';

function TaskBadge({ taskKey, taskId, projectId, title = null, className = '', onClick = null }) {
    if (!taskKey && !taskId) return null;
    const rawKey = String(taskKey || taskId);
    const displayKey = rawKey.startsWith('#') ? rawKey : rawKey;
    const cleanKey = rawKey.replace(/^#/, '');

    const to = projectId
        ? taskUrl(projectId, cleanKey)
        : `/search?q=${encodeURIComponent(cleanKey)}`;

    const content = (
        <span
            className={`inline-flex items-center gap-1 rounded-md border border-blue-200 bg-blue-50/90 px-1.5 py-0.5 font-mono text-xs font-semibold text-blue-700 shadow-2xs transition-all hover:border-blue-400 hover:bg-blue-100 hover:text-blue-900 dark:border-blue-800/80 dark:bg-blue-950/60 dark:text-blue-300 dark:hover:border-blue-700 dark:hover:bg-blue-900/60 ${className}`}
            title={title ? `${displayKey}: ${title}` : displayKey}
        >
            <span className="text-[0.65rem] opacity-60">#</span>
            <span className="truncate">{cleanKey}</span>
        </span>
    );

    if (onClick) {
        return (
            <button
                type="button"
                onClick={onClick}
                className="inline-flex cursor-pointer text-left focus:outline-none focus:ring-2 focus:ring-blue-500/50 rounded-md"
            >
                {content}
            </button>
        );
    }

    return (
        <Link
            to={to}
            onClick={(e) => e.stopPropagation()}
            className="inline-flex focus:outline-none focus:ring-2 focus:ring-blue-500/50 rounded-md"
        >
            {content}
        </Link>
    );
}

export default memo(TaskBadge);
