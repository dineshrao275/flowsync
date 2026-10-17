import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Spinner from '../ui/Spinner';
import Badge from '../ui/Badge';

/** Expected level (from the designation) against the person's own level, biggest gaps first. */
export default function SkillGaps() {
    const { t } = useTranslation();
    const [data, setData] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        api.get('/hrms/skills/mine')
            .then(({ data: mine }) => api.get(`/hrms/employees/${mine.employee_id}/skill-gaps`))
            .then(({ data: res }) => setData(res))
            .catch(() => setFailed(true));
    }, []);

    if (failed) return <p className="py-6 text-center text-sm text-gray-500">{t('hrms.skills.gaps.failed')}</p>;
    if (!data) return <div className="flex justify-center py-8"><Spinner /></div>;
    if (data.gaps.length === 0) return <p className="py-6 text-center text-sm text-gray-500">{data.has_designation ? t('hrms.skills.gaps.none') : t('hrms.skills.gaps.noDesignation')}</p>;

    return (
        <ul className="divide-y divide-gray-100">
            {data.gaps.map((g) => (
                <li key={g.skill_id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span className="font-medium text-gray-900">{g.skill}</span>
                    <span className="flex items-center gap-2 text-gray-600">
                        {t('hrms.skills.gaps.line', { level: g.level ?? '—', expected: g.expected })}
                        <Badge tone={g.gap > 0 ? 'warning' : 'success'}>{g.gap > 0 ? t('hrms.skills.gaps.short', { n: g.gap }) : t('hrms.skills.gaps.met')}</Badge>
                    </span>
                </li>
            ))}
        </ul>
    );
}
