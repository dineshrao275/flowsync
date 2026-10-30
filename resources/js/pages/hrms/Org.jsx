import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import DepartmentTreeColumn from '../../components/hrms/DepartmentTreeColumn';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import DepartmentFormModal from './DepartmentFormModal';
import DesignationFormModal from './DesignationFormModal';
import LocationFormModal from './LocationFormModal';
import { DepartmentPanel, CatalogPanel } from '../../components/hrms/OrgPanels';

/**
 * The org page: a department tree on the left, whatever the selection is on the
 * right.
 *
 * `GET api/hrms/org` returns the chart, the flat department list and both other
 * catalogues in one response, so the page is three queries on arrival and none
 * after a local reorder. The flat list is not redundant with the tree — the
 * tree knows nesting, the flat list is the authority on `position`, and the
 * reorder buttons need the server's sibling order rather than a JS
 * reconstruction of it.
 *
 * The selected department lives in the URL (`?department=`), for the same reason
 * the project and workspace pages keep their tab there: a link to a department
 * has to survive a refresh and be shareable, and a tree is exactly the thing
 * somebody sends to a colleague.
 */
export default function Org() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.org.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();
    // Read only: the tree's `Link`s drive the selection through the router,
    // so nothing on this page writes a search parameter itself.
    const [searchParams] = useSearchParams();

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [modal, setModal] = useState(null);
    const [people, setPeople] = useState([]);

    const canManage = can('permission:hrms.org.manage');
    const canViewEmployees = can('permission:hrms.employees.view');
    const canMoveEmployees = can('permission:hrms.employees.manage');

    const selectedId = searchParams.get('department');
    const selected = useMemo(
        () => data?.departments?.find((d) => String(d.id) === String(selectedId)) ?? null,
        [data, selectedId],
    );

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.org.pageTitle') }]);
    }, [setCrumbs, t]);

    const load = useCallback(() => {
        setError(null);

        return api
            .get('/hrms/org')
            .then(({ data: response }) => setData(response))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.org.loadFailed'));
            });
    }, [navigate, t]);

    useEffect(() => {
        load();
    }, [load]);

    // The head picker needs everybody, not the page the panel happens to show,
    // and `filters.managers` is the one uncapped people list the API exposes —
    // so this asks for a single row and reads the option list off the response.
    // Skipped entirely without employee visibility: a 403 in the console for a
    // picker the caller may not open is noise.
    useEffect(() => {
        if (!canViewEmployees) return;

        api
            .get('/hrms/employees', { params: { per_page: 1 } })
            .then(({ data: response }) => {
                const options = response?.filters?.people ?? response?.filters?.managers ?? [];
                setPeople(
                    options.map((person) => ({
                        id: person.id,
                        name: person.name ?? person.display_name,
                    })),
                );
            })
            .catch(() => setPeople([]));
    }, [canViewEmployees]);

    async function write(request, successMessage) {
        setSaving(true);

        try {
            const { data: response } = await request();
            setModal(null);
            toast.success(successMessage);
            await load();
            return response;
        } finally {
            setSaving(false);
        }
    }

    async function move(node, delta) {
        const siblings = data.departments
            .filter((d) => (d.parent_id ?? null) === (node.parent_id ?? null))
            .sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));

        const index = siblings.findIndex((d) => d.id === node.id);
        const target = index + delta;

        if (index === -1 || target < 0 || target >= siblings.length) return;

        const reordered = [...siblings.map((d) => d.id)];
        [reordered[index], reordered[target]] = [reordered[target], reordered[index]];

        try {
            // `parent_id` decides *which* list this is, and it belongs in the
            // query string: together with the path it names one sibling set, so
            // putting the parent anywhere else invites a client to reorder one
            // parent's children with another's ids.
            await api.post('/hrms/departments/reorder', { ids: reordered }, {
                params: node.parent_id ? { parent_id: node.parent_id } : {},
            });
            await load();
        } catch (err) {
            toast.error(err?.response?.data?.message ?? t('hrms.org.reorderFailed'));
        }
    }

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!data) {
        return (
            <div className="py-16 text-center">
                <ContentSkeleton size="sm" />
            </div>
        );
    }

    const hasCatalog = data.designations.length > 0 || data.locations.length > 0;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.org.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {t('hrms.org.counts', { departments: data.departments.length, designations: data.designations.length, locations: data.locations.length })}
                    </p>
                </div>

                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => setModal({ kind: 'department' })}>{t('hrms.org.newDepartment')}</Button>
                        <Button variant="secondary" onClick={() => setModal({ kind: 'designation' })}>
                            {t('hrms.org.newDesignation')}
                        </Button>
                        <Button variant="secondary" onClick={() => setModal({ kind: 'location' })}>
                            {t('hrms.org.newLocation')}
                        </Button>
                    </div>
                )}
            </div>

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <Card>
                    <DepartmentTreeColumn
                        tree={data.tree}
                        departments={data.departments}
                        selectedId={selected ? selected.id : null}
                        canManage={canManage}
                        onMove={move}
                    />
                </Card>

                <div className="space-y-4">
                    {selected ? (
                        <DepartmentPanel
                            department={selected}
                            designations={data.designations}
                            locations={data.locations}
                            canManage={canManage}
                            canViewEmployees={canViewEmployees}
                            canMoveEmployees={canMoveEmployees}
                            people={people}
                            saving={saving}
                            onEditDepartment={() => setModal({ kind: 'department', record: selected })}
                            onEditDesignation={(d) => setModal({ kind: 'designation', record: d })}
                            onEditLocation={(l) => setModal({ kind: 'location', record: l })}
                            onChanged={load}
                        />
                    ) : hasCatalog ? (
                        <CatalogPanel
                            designations={data.designations}
                            locations={data.locations}
                            canManage={canManage}
                            onEditDesignation={(d) => setModal({ kind: 'designation', record: d })}
                            onEditLocation={(l) => setModal({ kind: 'location', record: l })}
                        />
                    ) : (
                        <Card>
                            <EmptyState
                                title={t('hrms.org.emptyTitle')}
                                description={
                                    canManage
                                        ? t('hrms.org.emptyManage')
                                        : t('hrms.org.emptyView')
                                }
                            />
                        </Card>
                    )}
                </div>
            </div>

            {modal?.kind === 'department' && (
                <DepartmentFormModal
                    department={modal.record ?? null}
                    departments={data.departments}
                    heads={people}
                    saving={saving}
                    onClose={() => setModal(null)}
                    onSubmit={(payload) =>
                        modal.record
                            ? write(
                                  () => api.put(`/hrms/departments/${modal.record.id}`, payload),
                                  t('hrms.org.deptUpdated'),
                              )
                            : write(() => api.post('/hrms/departments', payload), t('hrms.org.deptCreated'))
                    }
                />
            )}

            {modal?.kind === 'designation' && (
                <DesignationFormModal
                    designation={modal.record ?? null}
                    departments={data.departments}
                    saving={saving}
                    onClose={() => setModal(null)}
                    onSubmit={(payload) =>
                        modal.record
                            ? write(
                                  () => api.put(`/hrms/designations/${modal.record.id}`, payload),
                                  t('hrms.org.desigUpdated'),
                              )
                            : write(() => api.post('/hrms/designations', payload), t('hrms.org.desigCreated'))
                    }
                />
            )}

            {modal?.kind === 'location' && (
                <LocationFormModal
                    location={modal.record ?? null}
                    saving={saving}
                    onClose={() => setModal(null)}
                    onSubmit={(payload) =>
                        modal.record
                            ? write(
                                  () => api.put(`/hrms/locations/${modal.record.id}`, payload),
                                  t('hrms.org.locUpdated'),
                              )
                            : write(() => api.post('/hrms/locations', payload), t('hrms.org.locCreated'))
                    }
                />
            )}
        </div>
    );
}

