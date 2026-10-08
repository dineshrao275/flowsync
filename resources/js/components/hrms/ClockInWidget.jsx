import Button from '../ui/Button';
import Card from '../ui/Card';
import Spinner from '../ui/Spinner';

function clockTime(iso) {
    if (!iso) return '—';

    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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
    if (!today) {
        return (
            <Card title="Clock in">
                <div className="flex justify-center py-6">
                    <Spinner />
                </div>
            </Card>
        );
    }

    const punches = today.punches ?? [];
    const last = punches[punches.length - 1] ?? null;
    const next = last?.direction === 'in' ? 'out' : 'in';
    const flagged = punches.filter((punch) => punch.is_out_of_range);

    return (
        <Card title="Clock in">
            <div className="space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-sm font-medium text-gray-900">Today · {today.status_label}</p>
                        <p className="text-xs text-gray-500">
                            {today.day
                                ? `In ${clockTime(today.day.first_in_at)} · Out ${clockTime(today.day.last_out_at)}`
                                : 'No day record yet'}
                        </p>
                    </div>
                    <Button onClick={() => onPunch(next)} disabled={punching || !allowPunch} title={allowPunch ? null : 'You can only clock yourself in'}>
                        {punching ? 'Punching…' : next === 'in' ? 'Clock in' : 'Clock out'}
                    </Button>
                </div>

                {flagged.length > 0 && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        {flagged.length} punch{flagged.length === 1 ? '' : 'es'} recorded outside the allowed
                        network or area{flagged[0]?.out_of_range_reason ? `: ${flagged[0].out_of_range_reason}` : ''}.
                        Your reviewer will see the flag.
                    </div>
                )}

                {punches.length > 0 && (
                    <ul className="divide-y divide-gray-100">
                        {punches.map((punch) => (
                            <li key={punch.id} className="flex items-center justify-between py-1.5 text-sm">
                                <span className="font-medium capitalize text-gray-700">{punch.direction}</span>
                                <span className="text-gray-500">
                                    {clockTime(punch.punch_at)}
                                    {punch.is_out_of_range && <span className="ml-1 text-amber-600">· flagged</span>}
                                    {punch.source === 'regularized' && <span className="ml-1 text-gray-400">· corrected</span>}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Card>
    );
}
