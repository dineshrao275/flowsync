import { useTranslation } from 'react-i18next';
import Button from '../ui/Button';
import Card from '../ui/Card';
import ContentSkeleton from '../ui/ContentSkeleton';
import i18n, { intlTag } from '../../i18n';

function clockTime(iso) {
    if (!iso) return '—';

    return new Date(iso).toLocaleTimeString(intlTag(i18n.language), { hour: '2-digit', minute: '2-digit' });
}

/**
 * The clock widget: punch in/out, today's punches, and the out-of-range
 * banner.
 *
 * State lives in the parent (the month page owns the `today` payload and
 * refetches it after every punch); this renders the action the current
 * state allows. The last punch decides the button: an open `in` offers
 * "Clock out", anything else offers "Clock in". An out-of-range punch is a
 * flag the reviewer reads, never a refusal — the banner says where, not
 * "access denied".
 *
 * The punch endpoint only ever clocks the caller, so when the parent is
 * showing another person's day (`allowPunch` false) the button renders
 * disabled rather than hiding: the widget reads the same on every day,
 * and the disabled state says whose day this is.
 */
export default function ClockInWidget({ today, punching, onPunch, allowPunch = true }) {
    const { t } = useTranslation();
    if (!today) {
        return (
            <Card title={t('hrms.clock.title')}>
                <ContentSkeleton size="sm" />
            </Card>
        );
    }

    const punches = today.punches ?? [];
    const last = punches[punches.length - 1] ?? null;
    const onBreak = last?.kind === 'break' && last?.direction === 'out';
    const next = last?.direction === 'in' ? 'out' : 'in';
    const flagged = punches.filter((punch) => punch.is_out_of_range);

    return (
        <Card title={t('hrms.clock.title')}>
            <div className="space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-sm font-medium text-gray-900">{t('hrms.clock.today', { status: today.status_label })}</p>
                        <p className="text-xs text-gray-500">
                            {today.day
                                ? t('hrms.clock.inOut', { inn: clockTime(today.day.first_in_at), out: clockTime(today.day.last_out_at) })
                                : t('hrms.clock.noDay')}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {onBreak ? (
                            <Button onClick={() => onPunch('in', 'break')} disabled={punching || !allowPunch}>
                                {punching ? t('hrms.clock.punching') : t('hrms.clock.endBreak')}
                            </Button>
                        ) : (
                            <>
                                {next === 'out' && (
                                    <Button variant="secondary" onClick={() => onPunch('out', 'break')} disabled={punching || !allowPunch}>
                                        {t('hrms.clock.startBreak')}
                                    </Button>
                                )}
                                <Button onClick={() => onPunch(next)} disabled={punching || !allowPunch} title={allowPunch ? null : t('hrms.clock.selfOnly')}>
                                    {punching ? t('hrms.clock.punching') : next === 'in' ? t('hrms.clock.clockIn') : t('hrms.clock.clockOut')}
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {flagged.length > 0 && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        {t('hrms.clock.flagged', { count: flagged.length, reason: flagged[0]?.out_of_range_reason ? `: ${flagged[0].out_of_range_reason}` : '' })}
                    </div>
                )}

                {punches.length > 0 && (
                    <ul className="divide-y divide-gray-100">
                        {punches.map((punch) => (
                            <li key={punch.id} className="flex items-center justify-between py-1.5 text-sm">
                                <span className="font-medium capitalize text-gray-700">
                                    {punch.kind === 'break' ? (punch.direction === 'out' ? t('hrms.clock.breakStart') : t('hrms.clock.breakEnd')) : punch.direction}
                                </span>
                                <span className="text-gray-500">
                                    {clockTime(punch.punch_at)}
                                    {punch.is_out_of_range && <span className="ml-1 text-amber-600">{t('hrms.clock.flaggedShort')}</span>}
                                    {punch.source === 'regularized' && <span className="ml-1 text-gray-400">{t('hrms.clock.corrected')}</span>}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Card>
    );
}
