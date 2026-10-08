import { useEffect, useState } from 'react';
import api from '../../services/api';
import Spinner from '../ui/Spinner';
import AuditTrail from './AuditTrail';

/**
 * The profile's Audit tab: one record's full trail, fetched by the stored
 * morph class the show endpoint ships (`subject_type`) — never a hardcoded
 * FQCN that rots the day a morph map lands.
 */
export default function EmployeeAudit({ employeeId, subjectType }) {
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!employeeId || !subjectType) return;

        let cancelled = false;
        setError(null);

        api.get(`/hrms/audit/${encodeURIComponent(subjectType)}/${employeeId}`)
            .then(({ data }) => {
                if (!cancelled) setRows(data.audit_logs ?? []);
            })
            .catch(() => {
                if (!cancelled) setError('Unable to load the audit trail.');
            });

        return () => {
            cancelled = true;
        };
    }, [employeeId, subjectType]);

    if (error) return <p className="py-8 text-center text-sm text-red-600">{error}</p>;
    if (!rows) return <Spinner />;

    return <AuditTrail rows={rows} />;
}
