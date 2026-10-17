import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Card from '../ui/Card';
import Button from '../ui/Button';

/** A private link to subscribe to your due tasks and approved leave from Google/Outlook/Apple Calendar. */
export default function CalendarFeedCard() {
    const { t } = useTranslation();
    const [active, setActive] = useState(null);
    const [url, setUrl] = useState(null);

    useEffect(() => { api.get('/calendar-feed').then(({ data }) => setActive(data.active)).catch(() => setActive(false)); }, []);

    async function rotate() {
        const { data } = await api.post('/calendar-feed');
        setActive(true);
        setUrl(data.url);
    }

    async function revoke() {
        await api.delete('/calendar-feed');
        setActive(false);
        setUrl(null);
    }

    if (active === null) return null;

    return (
        <Card title={t('admin.calendarFeed.title')} subtitle={t('admin.calendarFeed.subtitle')}>
            <div className="space-y-3 text-sm text-gray-700">
                {url && <p className="rounded-md bg-amber-50 p-3 text-amber-900">{t('admin.calendarFeed.once')} <code className="break-all font-mono text-xs">{url}</code></p>}
                <div className="flex gap-2">
                    <Button variant={active ? 'secondary' : 'primary'} onClick={rotate}>{active ? t('admin.calendarFeed.rotate') : t('admin.calendarFeed.create')}</Button>
                    {active && <Button variant="danger" onClick={revoke}>{t('admin.calendarFeed.revoke')}</Button>}
                </div>
            </div>
        </Card>
    );
}
