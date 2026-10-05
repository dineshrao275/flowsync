import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Spinner from '../../components/ui/Spinner';
import DepartmentTreeColumn from '../../components/hrms/DepartmentTreeColumn';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import DepartmentFormModal from './DepartmentFormModal';
import DepartmentMembersPanel from './DepartmentMembersPanel';
import DesignationFormModal from './DesignationFormModal';
import LocationFormModal from './LocationFormModal';

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
    usePageTitle('Organisation');
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
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Organisation' }]);
    }, [setCrumbs]);

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

                setError('Unable to load the organisation.');
            });
    }, [navigate]);

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
            toast.error(err?.response?.data?.message ?? 'Could not reorder the tree.');
        }
    }

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!data) {
        return (
            <div className="py-16 text-center">
                <Spinner />
            </div>
        );
    }

    const hasCatalog = data.designations.length > 0 || data.locations.length > 0;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Organisation</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {data.departments.length} department{data.departments.length === 1 ? '' : 's'} ·{' '}
                        {data.designations.length} designation{data.designations.length === 1 ? '' : 's'} ·{' '}
                        {data.locations.length} location{data.locations.length === 1 ? '' : 's'}
                    </p>
                </div>

                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => setModal({ kind: 'department' })}>New department</Button>
                        <Button variant="secondary" onClick={() => setModal({ kind: 'designation' })}>
                            New designation
                        </Button>
                        <Button variant="secondary" onClick={() => setModal({ kind: 'location' })}>
                            New location
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
                                title="Nothing selected"
                                description={
                                    canManage
                                        ? 'Pick a department on the left, or create the first one.'
                                        : 'Pick a department on the left to see its details.'
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
                                  'Department updated.',
                              )
                            : write(() => api.post('/hrms/departments', payload), 'Department created.')
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
                                  'Designation updated.',
                              )
                            : write(() => api.post('/hrms/designations', payload), 'Designation created.')
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
                                  'Location updated.',
                              )
                            : write(() => api.post('/hrms/locations', payload), 'Location created.')
                    }
                />
            )}
        </div>
    );
}

/**
 * The right-hand column for a selected department: who runs it, who is in it,
 * and the two catalogues it is worth looking at.
 */
function DepartmentPanel({
    department,
    designations,
    locations,
    canManage,
    canViewEmployees,
    canMoveEmployees,
    people,
    saving,
    onEditDepartment,
    onEditDesignation,
    onEditLocation,
    onChanged,
}) {
    const toast = useToast();

    async function retire() {
        try {
            await api.post(`/hrms/departments/${department.id}/deactivate`);
            toast.success(`${department.name} deactivated.`);
            await onChanged();
        } catch (err) {
            toast.error(err?.response?.data?.message ?? 'Could not deactivate the department.');
        }
    }

    const scopedDesignations = designations.filter((d) => d.department_id === department.id);
    const scopedLocations = locations.slice(0, 6);

    return (
        <>
            <Card>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 className="text-base font-semibold text-gray-900">
                            {department.name}
                            {!department.is_active && (
                                <span className="ml-2 text-xs font-normal text-gray-400">inactive</span>
                            )}
                        </h3>
                        <p className="mt-0.5 text-sm text-gray-500">
                            {department.code ? `${department.code} · ` : ''}
                            {department.employees_count} here · {department.children_count} sub-departments
                        </p>
                        {department.description && (
                            <p className="mt-2 text-sm text-gray-600">{department.description}</p>
                        )}
                    </div>

                    {canManage && (
                        <div className="flex gap-2">
                            <Button variant="secondary" size="sm" onClick={onEditDepartment}>
                                Edit
                            </Button>
                            {department.is_active && (
                                <Button variant="ghost" size="sm" onClick={retire} loading={saving}>
                                    Deactivate
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                {department.head && (
                    <p className="mt-3 text-sm text-gray-600">
                        Headed by{' '}
                        <span className="font-medium text-gray-800">{department.head.name}</span>
                    </p>
                )}
            </Card>

            <Card>
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">Members</h3>

                {!canViewEmployees ? (
                    <p className="text-sm text-gray-500">
                        Member names need the employee records permission, which this role does not hold.
                    </p>
                ) : (
                    <DepartmentMembersPanel
                        department={department}
                        canManage={canMoveEmployees}
                        onChanged={onChanged}
                    />
                )}
            </Card>

            <div className="grid gap-4 sm:grid-cols-2">
                <Card>
                    <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
                        Designations
                    </h3>
                    <CatalogList
                        items={scopedDesignations}
                        empty="No designation is scoped to this department."
                        onEdit={canManage ? onEditDesignation : null}
                        secondary={(d) => (d.level === null ? null : `Level ${d.level}`)}
                    />
                </Card>

                <Card>
                    <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">Locations</h3>
                    <CatalogList
                        items={scopedLocations}
                        empty="No locations yet."
                        onEdit={canManage ? onEditLocation : null}
                        secondary={(l) => (l.is_geo_fenced ? 'geofenced' : l.city || l.country)}
                    />
                </Card>
            </div>

            {people.length === 0 && canViewEmployees && (
                <p className="text-xs text-gray-400">
                    The head picker stays empty until there is somebody to head a department.
                </p>
            )}
        </>
    );
}

/**
 * Shown when nothing is selected: the two tenant-wide catalogues, so the page is
 * never a dead end on first load.
 */
function CatalogPanel({ designations, locations, canManage, onEditDesignation, onEditLocation }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Card>
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
                    Designations
                </h3>
                <CatalogList
                    items={designations}
                    empty="No designations yet."
                    onEdit={canManage ? onEditDesignation : null}
                    secondary={(d) => `${d.employees_count} holding`}
                />
            </Card>

            <Card>
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">Locations</h3>
                <CatalogList
                    items={locations}
                    empty="No locations yet."
                    onEdit={canManage ? onEditLocation : null}
                    secondary={(l) => `${l.employees_count} at this site`}
                />
            </Card>
        </div>
    );
}

function CatalogList({ items, empty, onEdit, secondary }) {
    if (items.length === 0) {
        return <p className="text-sm text-gray-500">{empty}</p>;
    }

    return (
        <ul className="divide-y divide-gray-100">
            {items.map((item) => (
                <li key={item.id} className="flex items-center gap-2 py-2 first:pt-0 last:pb-0">
                    <span className="min-w-0 flex-1 truncate text-sm text-gray-800">
                        {item.name}
                        {!item.is_active && <span className="ml-2 text-xs text-gray-400">inactive</span>}
                    </span>
                    <span className="shrink-0 text-xs text-gray-400">{secondary?.(item) ?? ''}</span>
                    {onEdit && (
                        <Button variant="ghost" size="sm" onClick={() => onEdit(item)}>
                            Edit
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}
