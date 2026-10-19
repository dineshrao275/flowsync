import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '../ui/Button';
import Input from '../ui/Input';
import { RANGE_PRESETS, presetLabel } from '../../utils/dateRange';

/**
 * Preset window picker shared by Reports and Dashboard: one row of preset
 * pills plus an inline custom range. Calls back with (presetKey, {from,to})
 * only — range math lives in utils/dateRange so both pages resolve the
 * same spans from the same labels.
 */
export default function RangeFilter({ preset, onChange }) {
    const { t } = useTranslation();
    const [custom, setCustom] = useState({ from: '', to: '' });

    function pick(key) {
        if (key === 'custom') {
            if (custom.from && custom.to && custom.from <= custom.to) {
                onChange('custom', { from: custom.from, to: custom.to });
            }
            return;
        }
        onChange(key, null);
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {RANGE_PRESETS.filter((key) => key !== 'custom').map((key) => (
                <Button
                    key={key}
                    size="sm"
                    variant={preset === key ? 'primary' : 'secondary'}
                    onClick={() => pick(key)}
                >
                    {presetLabel(key)}
                </Button>
            ))}
            <div className="flex items-center gap-2">
                <Input
                    type="date"
                    compact
                    aria-label={t('dashboard.range.start')}
                    value={custom.from}
                    max={custom.to || undefined}
                    onChange={(e) => setCustom((c) => ({ ...c, from: e.target.value }))}
                />
                <span className="text-xs text-gray-400">–</span>
                <Input
                    type="date"
                    compact
                    aria-label={t('dashboard.range.end')}
                    value={custom.to}
                    min={custom.from || undefined}
                    onChange={(e) => setCustom((c) => ({ ...c, to: e.target.value }))}
                />
                <Button
                    size="sm"
                    variant={preset === 'custom' ? 'primary' : 'secondary'}
                    disabled={!custom.from || !custom.to || custom.from > custom.to}
                    onClick={() => pick('custom')}
                >
                    {t('dashboard.range.apply')}
                </Button>
            </div>
        </div>
    );
}
