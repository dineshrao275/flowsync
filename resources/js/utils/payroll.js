const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

/**
 * Payroll display helpers. Month names and run-step hints live here rather
 * than in PHP: the backend speaks ids and statuses, and a JS copy of a PHP
 * *enum* would be drift no test can catch — but a month name is locale
 * display, not domain data.
 */
export function monthLabel(year, month) {
    const name = MONTHS[Number(month) - 1] ?? `Month ${month}`;

    return `${name} ${year}`;
}

export const RUN_STATUS_STEPS = {
    draft: 'not calculated yet',
    calculating: 'calculating now',
    review: 'in review',
    approved: 'approved, not published',
    processing: 'published, disbursing',
    paid: 'paid, not locked',
    void: 'abandoned',
    locked: 'sealed',
};

export const PAYSLIP_STATUS_LABELS = {
    draft: 'Draft',
    published: 'Published',
    disputed: 'Disputed',
    paid: 'Paid',
};
