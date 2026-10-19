import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import usePageTitle from '../hooks/usePageTitle';
import { getAppName } from '../utils/appName';

/** Holding page shown while platform maintenance mode is on. Polls the public status and returns when it ends. */
export default function Maintenance() {
    const { t } = useTranslation();
    usePageTitle(t('maintenance.title'));
    const [checking, setChecking] = useState(false);

    async function check() {
        setChecking(true);
        try {
            const { data } = await api.get('/maintenance');
            if (!data.enabled) window.location.href = '/app';
        } catch {
            /* still unreachable: keep waiting */
        } finally {
            setChecking(false);
        }
    }

    useEffect(() => {
        const id = setInterval(check, 20000);
        return () => clearInterval(id);
    }, []);

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4">
            <div className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-sm">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-2xl" aria-hidden="true">🛠</div>
                <h1 className="text-xl font-bold text-gray-900">{t('maintenance.title')}</h1>
                <p className="mt-2 text-sm text-gray-600">{t('maintenance.body', { app: getAppName() })}</p>
                <div className="mt-6 flex justify-center gap-3">
                    <button type="button" onClick={check} disabled={checking} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                        {t('maintenance.retry')}
                    </button>
                    <a href="/app/login" className="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 ring-1 ring-inset ring-gray-200">{t('maintenance.adminLogin')}</a>
                </div>
            </div>
        </div>
    );
}
