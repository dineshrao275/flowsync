import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import RichTextEditor from '../components/ui/RichTextEditor';
import { stripRichText } from '../components/ui/RichText';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

const icons = {
    owner: 'M7 21a4 4 0 01-4-4V5a2 2 0 012-2h1V2h2v1h8V2h2v1h1a2 2 0 012 2v12a4 4 0 01-4 4H7zm0-2h10a2 2 0 002-2V8H5v9a2 2 0 002 2zm1-6h8v2H8v-2zm0-4h8v2H8V7z',
    admin: 'M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4zm-1 10l-2-2-1 1 3 3 5-5-1-1-4 4z',
};

function MemberIcon({ role }) {
    const path = icons[role] || icons.member;
    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d={path} />
        </svg>
    );
}

export default function Workspaces() {
    const { t } = useTranslation();
    usePageTitle(t('tms.workspaces.pageTitle'));
    const { can } = useAuth();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [workspaces, setWorkspaces] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [creating, setCreating] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({ name: '', description: '' });
    const [formErrors, setFormErrors] = useState({});
    const canCreate = can('workspaces.create');

    async function load() {
        try {
            const { data } = await api.get('/workspaces');
            setWorkspaces(data.workspaces);
        } catch {
            setError(t('tms.workspaces.loadFailed'));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        load();
        setCrumbs([{ label: t('tms.workspaces.pageTitle') }]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function createWorkspace(e) {
        e.preventDefault();
        setSaving(true);
        setFormErrors({});
        try {
            const { data } = await api.post('/workspaces', form);
            setWorkspaces((current) => [...current, data.workspace]);
            setCreating(false);
            setForm({ name: '', description: '' });
            toast.success(t('tms.workspaces.created', { name: data.workspace.name }));
        } catch (e) {
            setFormErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('tms.workspaces.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('tms.workspaces.subtitle')}</p>
                </div>
                {canCreate && (
                    <Button onClick={() => setCreating((open) => !open)}>{t('tms.workspaces.new')}</Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {creating && (
                <Card title={t('tms.workspaces.createTitle')} subtitle={t('tms.workspaces.createSubtitle')}>
                    <form onSubmit={createWorkspace} className="space-y-4">
                        {formErrors.form && <Alert>{formErrors.form}</Alert>}
                        <Input
                            label={t('tms.workspaces.name')}
                            name="name"
                            placeholder={t('tms.workspaces.namePlaceholder')}
                            value={form.name}
                            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                            error={formErrors.name}
                            required
                        />
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.workspaces.description')}</label>
                            <RichTextEditor
                                value={form.description}
                                onChange={(html) => setForm((f) => ({ ...f, description: html }))}
                                placeholder={t('tms.workspaces.descriptionPlaceholder')}
                                error={formErrors.description}
                            />
                        </div>
                        <div className="flex gap-2 pt-1">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>
                                {t('tms.workspaces.cancel')}
                            </Button>
                            <Button type="submit" loading={saving}>
                                {t('tms.workspaces.create')}
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            <Table>
                <thead>
                    <tr>
                        <Th>{t('tms.workspaces.thWorkspace')}</Th>
                        <Th>{t('tms.workspaces.thDescription')}</Th>
                        <Th>{t('tms.workspaces.thRole')}</Th>
                        <Th align="right">{t('tms.workspaces.thMembers')}</Th>
                        <Th align="right">{t('tms.workspaces.thProjects')}</Th>
                        <Th align="right">{t('tms.workspaces.thLabels')}</Th>
                        <Th align="right">{t('tms.workspaces.thStatus')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {workspaces.length === 0 ? (
                        <TableEmpty colSpan={7}>{t('tms.workspaces.empty')}</TableEmpty>
                    ) : (
                        workspaces.map((workspace, index) => (
                            <tr
                                key={workspace.id}
                                className="animate-fade-in transition-colors duration-150 hover:bg-gray-50"
                                style={{ animationDelay: `${index * 30}ms` }}
                            >
                                <Td>
                                    <Link
                                        to={`/workspaces/${workspace.id}`}
                                        className="flex items-center gap-3 font-medium text-gray-900 hover:text-[var(--accent-soft-text)]"
                                    >
                                        <span
                                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-semibold text-white"
                                            style={{ backgroundColor: 'var(--accent)' }}
                                        >
                                            {workspace.name.charAt(0).toUpperCase()}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block truncate">{workspace.name}</span>
                                            <span className="block truncate text-xs font-normal text-gray-400">{workspace.slug}</span>
                                        </span>
                                    </Link>
                                </Td>
                                <Td>
                                        <span className="block max-w-md truncate text-gray-500">
                                            {stripRichText(workspace.description) || t('tms.workspaces.noDescription')}
                                        </span>
                                </Td>
                                <Td>
                                    <span
                                        className="inline-flex items-center gap-2 text-gray-500"
                                        title={t('tms.workspaces.roleTitle', { role: workspace.my_role || t('tms.workspaces.roleNone') })}
                                    >
                                        <MemberIcon role={workspace.my_role} />
                                        <span className="capitalize">{workspace.my_role || t('tms.workspaces.roleNone')}</span>
                                    </span>
                                </Td>
                                <Td align="right" className="tabular-nums">
                                    {workspace.members_count}
                                </Td>
                                <Td align="right" className="tabular-nums">
                                    {workspace.projects_count}
                                </Td>
                                <Td align="right" className="tabular-nums">
                                    {workspace.labels_count}
                                </Td>
                                <Td align="right">
                                    {workspace.archived_at ? (
                                        <Badge>{t('tms.workspaces.archived')}</Badge>
                                    ) : (
                                        <span className="text-xs text-gray-400">{t('tms.workspaces.active')}</span>
                                    )}
                                </Td>
                            </tr>
                        ))
                    )}
                </tbody>
            </Table>

            {workspaces.length === 0 && !creating && canCreate && (
                <div className="flex flex-col items-center gap-3 py-2 text-center">
                    <p className="text-sm text-gray-600">{t('tms.workspaces.emptyHint')}</p>
                    <Button onClick={() => setCreating(true)}>{t('tms.workspaces.emptyCta')}</Button>
                </div>
            )}
        </div>
    );
}