import React from 'react';
import { Link } from 'react-router-dom';
import { withTranslation } from 'react-i18next';
import Button from './ui/Button';
import logger from '../utils/logger';

/**
 * App-level error boundary: a render error in any routed page shows a
 * recoverable card instead of unmounting the whole SPA (sidebar and topbar
 * included). `resetKey` (the route pathname) clears the error on navigation.
 * HRMS keeps its own narrower boundary (`hrms/HrmsErrorBoundary`) so a broken
 * tab does not take the module rail down with it.
 */
class ErrorBoundary extends React.Component {
    state = { hasError: false, error: null };

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, info) {
        logger.error('ErrorBoundary', error.message || 'Component crashed', {
            stack: error.stack,
            componentStack: info?.componentStack,
        });
        if (typeof window !== 'undefined' && window.reportError) {
            window.reportError(error);
        }
    }

    componentDidUpdate(prevProps) {
        if (this.props.resetKey !== prevProps.resetKey && this.state.hasError) {
            this.setState({ hasError: false, error: null });
        }
    }

    reset = () => this.setState({ hasError: false, error: null });

    render() {
        if (!this.state.hasError) {
            return this.props.children;
        }

        const { t } = this.props;

        return (
            <div
                role="alert"
                className="rounded-xl border border-red-200 p-6 shadow-sm dark:border-red-900/40"
                style={{ backgroundColor: 'var(--card-bg, #ffffff)' }}
            >
                <h2 className="text-base font-semibold text-gray-900 dark:text-blue-100">
                    {t('shell.errorBoundary.title')}
                </h2>
                <p className="mt-1 text-sm text-gray-600 dark:text-blue-400">
                    {t('shell.errorBoundary.desc')}
                </p>
                <div className="mt-3 overflow-x-auto rounded-md bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-800/80 dark:text-blue-300">
                    {this.state.error?.message || t('shell.errorBoundary.fallback')}
                </div>
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <Button variant="secondary" onClick={this.reset}>
                        {t('shell.errorBoundary.retry')}
                    </Button>
                    <Link to="/">
                        <Button variant="secondary">{t('shell.errorBoundary.dashboard')}</Button>
                    </Link>
                </div>
            </div>
        );
    }
}

export default withTranslation()(ErrorBoundary);
