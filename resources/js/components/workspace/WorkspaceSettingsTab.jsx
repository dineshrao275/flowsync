import { useTranslation } from 'react-i18next';
import Card from '../ui/Card';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import RichTextEditor from '../ui/RichTextEditor';

export function WorkspaceSettingsTab({
    workspace,
    updateWorkspace,
    settingsDescription,
    setSettingsDescription,
    formErrors,
    updating,
    deleteWorkspace,
}) {
    const { t } = useTranslation();

    return (
        <Card title={t('tms.workspaceDetail.settingsTitle')} subtitle={`slug: ${workspace.slug}`}>
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    updateWorkspace({
                        name: e.currentTarget.name.value,
                        description: settingsDescription || undefined,
                    });
                }}
            >
                {formErrors.form && <Alert>{formErrors.form}</Alert>}
                <Input
                    label={t('tms.workspaceDetail.settingsName')}
                    name="name"
                    defaultValue={workspace.name}
                    error={formErrors.name}
                    required
                />
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">
                        {t('tms.workspaceDetail.settingsDesc')}
                    </label>
                    <RichTextEditor
                        value={settingsDescription}
                        onChange={setSettingsDescription}
                        placeholder={t('tms.workspaceDetail.settingsDescPlaceholder')}
                        error={formErrors.description}
                    />
                </div>
                <div className="flex gap-2">
                    <Button type="submit" loading={updating}>
                        {t('tms.workspaceDetail.save')}
                    </Button>
                    <Button type="button" variant="danger" onClick={deleteWorkspace}>
                        {t('tms.workspaceDetail.deleteWorkspace')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}
