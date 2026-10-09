import React from 'react';
import { Link } from 'react-router-dom';
import Button from '../ui/Button';

/**
 * H8 — Graceful error boundary for HRMS hub pages.
 *
 * Prevents a runtime error in any individual HRMS page (which can be large)
 * from crashing the whole module shell or unmounting the navigation rail.
 * Automatically clears when `resetKey` (route pathname) changes.
 */
export default class HrmsErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error) {
        // Log locally for debugging without crashing React tree
        if (typeof window !== 'undefined' && window.reportError) {
            window.reportError(error);
        }
    }

    componentDidUpdate(prevProps) {
        if (this.props.resetKey !== prevProps.resetKey && this.state.hasError) {
            this.setState({ hasError: false, error: null });
        }
    }

    handleReset = () => {
        this.setState({ hasError: false, error: null });
    };

    render() {
        if (this.state.hasError) {
            const { error } = this.state;
            const message = error?.message || 'An unexpected error occurred while rendering this section.';

            return (
                <div
                    role="alert"
                    aria-live="assertive"
                    className="rounded-xl border border-red-200 bg-white p-6 shadow-sm dark:border-red-900/40 dark:bg-gray-900"
                    style={{ backgroundColor: 'var(--card-bg, #ffffff)' }}
                >
                    <div className="flex items-start gap-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400">
                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth={2}
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                />
                            </svg>
                        </div>
                        <div className="min-w-0 flex-1">
                            <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                                This HRMS section encountered an unexpected error
                            </h3>
                            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                An isolated problem occurred loading this tab. The rest of FlowSync and your navigation rail remain fully functional.
                            </p>
                            <div className="mt-3 rounded-md bg-gray-50 p-3 text-xs font-mono text-gray-700 dark:bg-gray-800/80 dark:text-gray-300 overflow-x-auto">
                                {message}
                            </div>
                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                <Button variant="secondary" onClick={this.handleReset}>
                                    Try again
                                </Button>
                                <Link to="/hrms">
                                    <Button variant="secondary">
                                        Back to HRMS Overview
                                    </Button>
                                </Link>
                                <a
                                    href={`mailto:support@flowsync.test?subject=${encodeURIComponent('HRMS UI Error Report')}&body=${encodeURIComponent(
                                        `Error: ${message}\nURL: ${typeof window !== 'undefined' ? window.location.href : ''}`,
                                    )}`}
                                    className="text-xs text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 hover:underline"
                                >
                                    Report issue
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
