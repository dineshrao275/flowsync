import { cloneElement, isValidElement, useId, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../context/AuthContext';
import Avatar from './Avatar';
import Badge from './Badge';

/**
 * Context-aware user tooltip / popover card.
 *
 * Displays rich user metadata (photo, name, role, email, department, employee ID)
 * on hover or keyboard focus, with an accessible link to their profile if permissions allow.
 */
export default function UserTooltip({
    user = null,
    name = null,
    children,
    placement = 'top',
    clickable = true,
    className = '',
}) {
    const { t } = useTranslation();
    let currentUser = null;
    let can = null;
    try {
        const auth = useAuth();
        currentUser = auth?.user ?? null;
        can = auth?.can ?? null;
    } catch {
        // Render gracefully in contexts or isolated test trees without AuthProvider
    }
    const id = useId();
    const [open, setOpen] = useState(false);
    const enterTimeoutRef = useRef(null);
    const leaveTimeoutRef = useRef(null);

    const displayName = (typeof user === 'string' ? user : user?.name) || name || '';
    if (!displayName && !user) {
        return children;
    }

    const email = typeof user === 'object' ? user?.email : null;
    const role = typeof user === 'object'
        ? (user?.role_name || (Array.isArray(user?.roles) ? user?.roles[0]?.name || user?.roles[0] : user?.role) || (user?.is_super_admin ? 'Super Admin' : null))
        : null;
    const department = typeof user === 'object' ? (user?.department?.name || user?.department_name || user?.department) : null;
    const jobTitle = typeof user === 'object' ? (user?.job_title || user?.designation || user?.title) : null;
    const employeeId = typeof user === 'object' ? (user?.employee_id || user?.employee?.id) : null;
    const isSelf = Boolean(currentUser?.id && user?.id && currentUser.id === user.id);

    // Resolve profile destination based on tenant permissions
    let profileUrl = null;
    if (clickable) {
        if (can && can('users.view') && user?.id) {
            profileUrl = `/users/${user.id}`;
        } else if (can && (can('hrms.employees.view') || can('permission:hrms.employees.view')) && employeeId) {
            profileUrl = `/hrms/employees/${employeeId}`;
        } else if (isSelf) {
            profileUrl = '/my';
        }
    }

    const handleMouseEnter = () => {
        clearTimeout(leaveTimeoutRef.current);
        enterTimeoutRef.current = setTimeout(() => setOpen(true), 130);
    };

    const handleMouseLeave = () => {
        clearTimeout(enterTimeoutRef.current);
        leaveTimeoutRef.current = setTimeout(() => setOpen(false), 160);
    };

    const handleFocus = () => setOpen(true);
    const handleBlur = () => {
        clearTimeout(enterTimeoutRef.current);
        setOpen(false);
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Escape') {
            clearTimeout(enterTimeoutRef.current);
            clearTimeout(leaveTimeoutRef.current);
            setOpen(false);
        }
    };

    const isBottom = placement === 'bottom';
    const positionClass = isBottom
        ? 'top-full mt-2'
        : 'bottom-full mb-2';

    return (
        <span
            className={`relative inline-flex items-center ${className}`}
            onMouseEnter={handleMouseEnter}
            onMouseLeave={handleMouseLeave}
            onFocus={handleFocus}
            onBlur={handleBlur}
            onKeyDown={handleKeyDown}
        >
            {isValidElement(children)
                ? cloneElement(children, {
                      'aria-describedby': open ? id : undefined,
                  })
                : children}

            {open && (
                <div
                    id={id}
                    role="tooltip"
                    onMouseEnter={() => clearTimeout(leaveTimeoutRef.current)}
                    onMouseLeave={handleMouseLeave}
                    className={`absolute left-1/2 z-[100] w-64 -translate-x-1/2 rounded-xl border border-gray-200/90 bg-white p-3.5 text-left shadow-xl animate-fade-in dark:border-slate-800 dark:bg-slate-900 ${positionClass}`}
                >
                    <div className="flex items-start gap-3">
                        <Avatar
                            name={displayName}
                            photoUrl={typeof user === 'object' ? user?.photo_url : null}
                            size="md"
                            className="ring-2 ring-gray-100 dark:ring-slate-800"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {displayName}
                            </p>
                            {role && (
                                <div className="mt-0.5">
                                    <Badge tone="accent" className="text-[0.65rem] uppercase tracking-wider">
                                        {role}
                                    </Badge>
                                </div>
                            )}
                            {jobTitle && !role && (
                                <p className="truncate text-xs text-gray-500 dark:text-gray-400">
                                    {jobTitle}
                                </p>
                            )}
                        </div>
                    </div>

                    {(email || department) && (
                        <div className="mt-2.5 space-y-1 border-t border-gray-100 pt-2 text-xs text-gray-600 dark:border-slate-800 dark:text-gray-400">
                            {email && (
                                <p className="truncate">
                                    <span className="font-medium text-gray-500 dark:text-gray-400">Email:</span>{' '}
                                    <a
                                        href={`mailto:${email}`}
                                        className="text-[var(--accent-soft-text)] hover:underline"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        {email}
                                    </a>
                                </p>
                            )}
                            {department && (
                                <p className="truncate">
                                    <span className="font-medium text-gray-500 dark:text-gray-400">Dept:</span> {department}
                                </p>
                            )}
                        </div>
                    )}

                    {profileUrl && (
                        <div className="mt-2.5 border-t border-gray-100 pt-2 text-right dark:border-slate-800">
                            <Link
                                to={profileUrl}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setOpen(false);
                                }}
                                className="inline-flex items-center gap-1 text-xs font-semibold text-[var(--accent-soft-text)] hover:text-[var(--accent)] hover:underline"
                            >
                                {t('common.viewProfile', { defaultValue: 'View Profile' })} &rarr;
                            </Link>
                        </div>
                    )}
                </div>
            )}
        </span>
    );
}
