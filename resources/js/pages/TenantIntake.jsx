import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import IntakeWizard from '../components/tenant/IntakeWizard';
import Spinner from '../components/ui/Spinner';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

const EMPTY = {
    name: '', slug: '', industry: '', company_size: '', country: '',
    billing_email: '', contact_name: '', contact_email: '',
    admin_name: '', admin_email: '', plan_id: '', start_trial: true, payment_method: '',
};

/**
 * Super Admin tenant wizard — create (/tenants/new) and resume a draft
 * (/tenants/:id/setup). Each step is saved as a draft; the database is only
 * created by the final submit, once the server agrees every required field is in.
 */
export default function TenantIntake() {
    const { tenantId } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    usePageTitle(tenantId ? 'Finish tenant setup' : 'New tenant');

    const [draftId, setDraftId] = useState(tenantId ? Number(tenantId) : null);
    const [values, setValues] = useState(EMPTY);
    const [plans, setPlans] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: 'Tenants', to: '/tenants' }, { label: tenantId ? 'Finish setup' : 'New tenant' }]);
    }, [setCrumbs, tenantId]);

    useEffect(() => {
        let active = true;
        Promise.all([
            api.get('/plans'),
            tenantId ? api.get(`/tenants/${tenantId}/intake`) : Promise.resolve(null),
        ])
            .then(([planRes, intakeRes]) => {
                if (!active) return;
                setPlans(planRes.data.plans.filter((p) => p.is_active));
                if (intakeRes) {
                    const v = intakeRes.data.intake.values;
                    setValues({ ...EMPTY, ...Object.fromEntries(Object.entries(v).filter(([, x]) => x !== null && x !== undefined)) });
                }
            })
            .catch(() => active && toast.error('Unable to load the tenant wizard.'))
            .finally(() => active && setLoading(false));
        return () => { active = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tenantId]);

    // Send only what has a value: a blank must not overwrite saved data or trip nullable rules.
    function payload() {
        return Object.fromEntries(
            Object.entries(values).filter(([, v]) => v !== '' && v !== null && v !== undefined),
        );
    }

    async function persist() {
        setErrors({});
        try {
            if (draftId) {
                await api.put(`/tenants/${draftId}/intake`, payload());
                return draftId;
            }
            const { data } = await api.post('/tenants', payload());
            setDraftId(data.tenant.id);
            return data.tenant.id;
        } catch (e) {
            setErrors(fieldErrors(e));
            return false;
        }
    }

    async function saveStep() {
        setSaving(true);
        try {
            return Boolean(await persist());
        } finally {
            setSaving(false);
        }
    }

    async function submit(password, confirmation) {
        setSaving(true);
        try {
            const id = await persist();
            if (!id) return;
            await api.post(`/tenants/${id}/intake/submit`, {
                admin_password: password,
                admin_password_confirmation: confirmation,
            });
            toast.success(`Tenant "${values.name}" is being provisioned.`);
            navigate(`/tenants/${id}`, { replace: true });
        } catch (e) {
            setErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return <div className="flex justify-center py-20"><Spinner /></div>;
    }

    return (
        <div className="mx-auto max-w-4xl space-y-4">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{tenantId ? 'Finish tenant setup' : 'New tenant'}</h2>
                <p className="mt-1 text-sm text-gray-500">
                    The tenant stays a draft, with no database, until every required field is complete.
                </p>
            </div>
            <IntakeWizard
                mode="admin"
                values={values}
                onChange={setValues}
                plans={plans}
                errors={errors}
                saving={saving}
                onSaveStep={saveStep}
                onSubmit={submit}
                submitLabel="Create & provision tenant"
            />
        </div>
    );
}
