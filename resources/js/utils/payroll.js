import i18n, { intlTag } from '../i18n';

/**
 * Payroll display helpers. Month names, the run-step hint and the payslip
 * status pill are render-time copy (i18n), not domain data — the backend
 * speaks ids and statuses, and a JS copy of a PHP *enum* would be drift no
 * test can catch. The status helpers take `t` so the dictionary stays the
 * single source for every visible label.
 */
export function monthLabel(year, month) {
    const value = Number(month);
    const name = value >= 1 && value <= 12
        ? new Date(2000, value - 1, 1).toLocaleDateString(intlTag(i18n.language), { month: 'long' })
        : `Month ${month}`;

    return `${name} ${year}`;
}

export function runStepHint(t, status) {
    return t(`hrms.payroll.runSteps.${status}`, { defaultValue: status });
}

export function payslipStatusLabel(t, status) {
    return t(`hrms.payroll.payslipStatuses.${status}`, { defaultValue: status });
}
