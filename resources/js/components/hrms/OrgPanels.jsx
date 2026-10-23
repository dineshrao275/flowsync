import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Button from '../ui/Button';
import Card from '../ui/Card';
import RichText from '../ui/RichText';
import { useToast } from '../../context/ToastContext';
import DepartmentMembersPanel from '../../pages/hrms/DepartmentMembersPanel';
import { useConfirm } from '../ui/ConfirmDialog';

/**
 * The right-hand column for a selected department: who runs it, who is in it,
 * and the two catalogues it is worth looking at.
 */
export function DepartmentPanel({
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
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();

    async function retire() {
        if (!(await confirm({
            title: t('hrms.org.deactivateTitle'),
            message: t('hrms.org.deactivateMessage', { name: department.name }),
            variant: 'danger',
            confirmLabel: t('hrms.org.deactivate'),
        }))) return;

        try {
            await api.post(`/hrms/departments/${department.id}/deactivate`);
            toast.success(t('hrms.org.deactivated', { name: department.name }));
            await onChanged();
        } catch (err) {
            toast.error(err?.response?.data?.message ?? t('hrms.org.deactivateFailed'));
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
                                <span className="ml-2 text-xs font-normal text-gray-400">{t('hrms.org.inactive')}</span>
                            )}
                        </h3>
                        <p className="mt-0.5 text-sm text-gray-500">
                            {department.code ? `${department.code} · ` : ''}
                            {t('hrms.org.deptCounts', { here: department.employees_count, subs: department.children_count })}
                        </p>
                        {department.description && (
                            <RichText value={department.description} className="mt-2 text-gray-600" />
                        )}
                    </div>

                    {canManage && (
                        <div className="flex gap-2">
                            <Button variant="secondary" size="sm" onClick={onEditDepartment}>
                                {t('hrms.org.edit')}
                            </Button>
                            {department.is_active && (
                                <Button variant="ghost" size="sm" onClick={retire} loading={saving}>
                                    {t('hrms.org.deactivate')}
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                {department.head && (
                    <p className="mt-3 text-sm text-gray-600">
                        {t('hrms.org.headedBy')}{' '}
                        <span className="font-medium text-gray-800">{department.head.name}</span>
                    </p>
                )}
            </Card>

            <Card>
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">{t('hrms.org.members')}</h3>

                {!canViewEmployees ? (
                    <p className="text-sm text-gray-500">
                        {t('hrms.org.membersNoPermission')}
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
                        {t('hrms.org.designations')}
                    </h3>
                    <CatalogList
                        items={scopedDesignations}
                        empty={t('hrms.org.emptyScopedDesig')}
                        onEdit={canManage ? onEditDesignation : null}
                        secondary={(d) => (d.level === null ? null : t('hrms.org.level', { level: d.level }))}
                    />
                </Card>

                <Card>
                    <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">{t('hrms.org.locations')}</h3>
                    <CatalogList
                        items={scopedLocations}
                        empty={t('hrms.org.emptyLocations')}
                        onEdit={canManage ? onEditLocation : null}
                        secondary={(l) => (l.is_geo_fenced ? t('hrms.org.geofenced') : l.city || l.country)}
                    />
                </Card>
            </div>

            {people.length === 0 && canViewEmployees && (
                <p className="text-xs text-gray-400">
                    {t('hrms.org.headPickerEmpty')}
                </p>
            )}
        </>
    );
}

/**
 * Shown when nothing is selected: the two tenant-wide catalogues, so the page is
 * never a dead end on first load.
 */
export function CatalogPanel({ designations, locations, canManage, onEditDesignation, onEditLocation }) {
    const { t } = useTranslation();
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Card>
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {t('hrms.org.designations')}
                </h3>
                <CatalogList
                    items={designations}
                    empty={t('hrms.org.emptyDesignations')}
                    onEdit={canManage ? onEditDesignation : null}
                    secondary={(d) => t('hrms.org.holding', { count: d.employees_count })}
                />
            </Card>

            <Card>
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">{t('hrms.org.locations')}</h3>
                <CatalogList
                    items={locations}
                    empty={t('hrms.org.emptyLocations')}
                    onEdit={canManage ? onEditLocation : null}
                    secondary={(l) => t('hrms.org.atSite', { count: l.employees_count })}
                />
            </Card>
        </div>
    );
}

export function CatalogList({ items, empty, onEdit, secondary }) {
    const { t } = useTranslation();
    if (items.length === 0) {
        return <p className="text-sm text-gray-500">{empty}</p>;
    }

    return (
        <ul className="divide-y divide-gray-100">
            {items.map((item) => (
                <li key={item.id} className="flex items-center gap-2 py-2 first:pt-0 last:pb-0">
                    <span className="min-w-0 flex-1 truncate text-sm text-gray-800">
                        {item.name}
                        {!item.is_active && <span className="ml-2 text-xs text-gray-400">{t('hrms.org.inactive')}</span>}
                    </span>
                    <span className="shrink-0 text-xs text-gray-400">{secondary?.(item) ?? ''}</span>
                    {onEdit && (
                        <Button variant="ghost" size="sm" onClick={() => onEdit(item)}>
                            {t('hrms.common.edit')}
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}
