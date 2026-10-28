import { useTranslation } from 'react-i18next';
import Card from '../ui/Card';
import Button from '../ui/Button';
import Input from '../ui/Input';

export function WorkspaceLabelsTab({
    isManager,
    addLabel,
    labelForm,
    setLabelForm,
    formErrors,
    savingLabel,
    labels,
    deleteLabel,
}) {
    const { t } = useTranslation();

    return (
        <div className="space-y-5">
            {isManager && (
                <Card title={t('tms.workspaceDetail.newLabelTitle')}>
                    <form onSubmit={addLabel} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="flex-1">
                            <Input
                                label={t('tms.workspaceDetail.labelName')}
                                name="label-name"
                                placeholder={t('tms.workspaceDetail.labelNamePlaceholder')}
                                value={labelForm.name}
                                onChange={(e) => setLabelForm((f) => ({ ...f, name: e.target.value }))}
                                error={formErrors.name}
                                required
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">
                                {t('tms.workspaceDetail.color')}
                            </label>
                            <input
                                type="color"
                                className="h-[42px] w-16 rounded-lg border border-gray-300 bg-white p-1 shadow-sm focus:outline-none"
                                value={labelForm.color}
                                onChange={(e) => setLabelForm((f) => ({ ...f, color: e.target.value }))}
                            />
                        </div>
                        <Button type="submit" loading={savingLabel}>
                            {t('tms.workspaceDetail.addLabel')}
                        </Button>
                    </form>
                </Card>
            )}

            <Card title={t('tms.workspaceDetail.labelsTitle')}>
                {labels.length === 0 ? (
                    <p className="text-sm text-gray-500">{t('tms.workspaceDetail.labelsEmpty')}</p>
                ) : (
                    <ul className="flex flex-wrap gap-2">
                        {labels.map((label) => (
                            <li
                                key={label.id}
                                className="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm"
                                style={{ backgroundColor: `${label.color}1a`, color: label.color }}
                            >
                                <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: label.color }} />
                                {label.name}
                                {isManager && (
                                    <button
                                        type="button"
                                        className="text-inherit opacity-60 transition hover:opacity-100"
                                        onClick={() => deleteLabel(label)}
                                        aria-label={t('tms.workspaceDetail.deleteLabelAria', { name: label.name })}
                                    >
                                        ×
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}
