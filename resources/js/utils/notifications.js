import { projectUrl, taskUrl, workspaceUrl } from './deepLinks';
import { formatRelativeTime } from './format';

export function describeNotification(t, type, data = {}, actorName = null) {
    const actor = actorName ?? t('notifications.someone');
    const key = data.key || t('notifications.taskKeyFallback');
    switch (type) {
        case 'task.assigned':
            return t('notifications.taskAssigned', { actor, key });
        case 'task.commented':
            return t('notifications.taskCommented', { actor, key });
        case 'task.work_logged':
            return t('notifications.taskWorkLogged', { actor, duration: data.duration_minutes ?? '', key });
        case 'task.status_changed':
            return t('notifications.taskStatusChanged', { actor, key, status: data.to_status });
        case 'task.unblocked':
            return t('notifications.taskUnblocked', { actor, key });
        case 'hrms.onboarding.task_due':
            return describeTaskDue(t, data);
        case 'hrms.attendance.regularization.requested':
            return t('notifications.regularizationRequested', { actor, date: data.work_date ?? t('notifications.fallbackPastDate') });
        case 'hrms.attendance.regularization.decided':
            return t('notifications.regularizationDecided', { date: data.work_date ?? t('notifications.fallbackPastDate'), status: data.status ?? t('notifications.fallbackDecided') });
        case 'hrms.leave.requested':
            return t('notifications.leaveRequested', {
                actor,
                days: data.total_days ?? '',
                type: data.leave_type_name ?? t('notifications.fallbackLeave'),
                from: data.from_date ?? '',
                to: data.to_date ?? '',
            });
        case 'hrms.leave.approved':
            return t('notifications.leaveApproved', { from: data.from_date ?? t('notifications.fallbackThoseDates') });
        case 'hrms.leave.rejected':
            return t('notifications.leaveRejected', { from: data.from_date ?? t('notifications.fallbackThoseDates') });
        case 'hrms.comp_off.requested':
            return t('notifications.compOffRequested', { actor, minutes: data.total_minutes ?? '', from: data.from_date ?? '', to: data.to_date ?? '' });
        case 'hrms.comp_off.approved':
            return t('notifications.compOffApproved', { from: data.from_date ?? t('notifications.fallbackThoseDates') });
        case 'hrms.comp_off.rejected':
            return t('notifications.compOffRejected', { from: data.from_date ?? t('notifications.fallbackThoseDates') });
        case 'hrms.expense.submitted':
            return t('notifications.expenseSubmitted', { actor, claim: data.claim_number ?? '' });
        case 'hrms.expense.approved':
            return t('notifications.expenseApproved', { claim: data.claim_number ?? '' });
        case 'hrms.expense.rejected':
            return t('notifications.expenseRejected', { claim: data.claim_number ?? '' });
        case 'hrms.expense.paid':
            return t('notifications.expensePaid', { claim: data.claim_number ?? '' });
        case 'hrms.performance.cycle_completed':
            return t('notifications.performanceCycleCompleted', { cycle: data.cycle_name ?? t('notifications.fallbackReview') });
        case 'hrms.performance.review_acknowledged':
            return t('notifications.performanceReviewAcknowledged');
        case 'hrms.offboarding.clearance_pending':
            return t('notifications.offboardingClearancePending', { employee: data.employee_name ?? t('notifications.fallbackSomeone') });
        case 'hrms.document.verified':
            return t('notifications.documentVerified', { title: data.title ?? t('notifications.fallbackDocument') });
        case 'hrms.document.rejected':
            return t('notifications.documentRejected', { title: data.title ?? t('notifications.fallbackDocument') });
        case 'hrms.performance.cycle_opened':
            return t('notifications.performanceCycleOpened', { cycle: data.cycle_name ?? t('notifications.fallbackReview') });
        case 'hrms.performance.review_shared':
            return t('notifications.performanceReviewShared');
        case 'talent.review_finalized':
            return t('notifications.talentReviewFinalized');
        case 'hrms.asset.assigned':
            return t('notifications.assetAssigned', { actor, asset: data.asset_code ?? t('notifications.fallbackAnAsset') });
        case 'hrms.asset.acknowledged':
            return t('notifications.assetAcknowledged', { employee: data.employee_name ?? t('notifications.someone'), asset: data.asset_code ?? t('notifications.fallbackTheirAsset') });
        case 'hrms.asset.returned':
            return t('notifications.assetReturned', { asset: data.asset_code ?? t('notifications.fallbackAnAsset') });
        case 'hrms.asset.return_overdue':
            return t('notifications.assetReturnOverdue', { asset: data.asset_code ?? t('notifications.fallbackAnAsset') });
        case 'hrms.survey.invited':
            return t('notifications.surveyInvited', { campaign: data.campaign_name ?? t('notifications.fallbackASurvey') });
        case 'hrms.survey.closing_soon':
            return t('notifications.surveyClosingSoon', { campaign: data.campaign_name ?? t('notifications.fallbackASurvey') });
        case 'hrms.inbox.digest':
            return t('notifications.inboxDigest', { schedule: data.schedule_name ?? t('notifications.fallbackYourDigest') });
        case 'automation.notice':
            return data.message || t('notifications.automationNotice', { key });
        case 'support.reply':
            return data.subject
                ? t('notifications.supportReplySubject', { reference: data.reference ?? t('notifications.fallbackYourTicket'), subject: data.subject })
                : t('notifications.supportReply', { reference: data.reference ?? t('notifications.fallbackYourTicket') });
        case 'support.resolved':
            return t('notifications.supportResolved', { reference: data.reference ?? t('notifications.fallbackYourTicket') });
        case 'support.closed':
            return t('notifications.supportClosed', { reference: data.reference ?? t('notifications.fallbackYourTicket') });
        case 'billing.payment_failed':
            return t('notifications.billingPaymentFailed');
        case 'billing.final_notice':
            return t('notifications.billingFinalNotice');
        case 'billing.suspended':
            return t('notifications.billingSuspended');
        default:
            return t('notifications.new');
    }
}

/**
 * An onboarding nudge names the item, the hire, and the urgency — not the
 * actor, because the sender is a command, not a person, and “Someone wants
 * you to …” would be a lie about who asked.
 */
function describeTaskDue(t, data = {}) {
    const what = data.title ? t('notifications.onboardingWhat', { title: data.title }) : t('notifications.onboardingWhatFallback');
    const who = data.employee_name ? t('notifications.onboardingWho', { employee: data.employee_name }) : '';

    if (!data.due_date) return t('notifications.onboardingNeedsAttention', { what, who });

    const today = new Date().toISOString().slice(0, 10);

    if (data.due_date < today) return t('notifications.onboardingOverdue', { what, who, date: data.due_date });
    if (data.due_date === today) return t('notifications.onboardingDueToday', { what, who });

    return t('notifications.onboardingDueOn', { what, who, date: data.due_date });
}

const SECTION_BY_TYPE = {
    'task.commented': 'comments',
    'task.work_logged': 'time',
    'task.unblocked': 'dependencies',
};

/**
 * Builds a deep link for a notification. Task notifications land on the
 * project's Tasks tab with the drawer auto-opened on the relevant section
 * (comments for task.commented, time for task.work_logged, details otherwise).
 * Onboarding nudges land on the case detail itself — the list page takes no
 * case parameter, so linking there would drop the reader one click away from
 * the item they were nudged about.
 */
export function notificationHref(data = {}, type = '') {
    const { project_id, workspace_id, key, onboarding_case_id } = data || {};

    if (type.startsWith('billing.')) {
        return '/subscription';
    }

    if (type.startsWith('support.') && data?.ticket_id) {
        return `/support/${data.ticket_id}`;
    }

    if (type === 'hrms.onboarding.task_due' && onboarding_case_id) {
        return `/hrms/onboarding/cases/${onboarding_case_id}`;
    }

    // A correction ask lands on the review queue; its decision lands on the
    // month the decision changed (the queue takes no request parameter, so
    // linking a decision there would drop the reader one click away).
    if (type === 'hrms.attendance.regularization.requested') {
        return '/hrms/attendance/approvals';
    }

    if (type === 'hrms.attendance.regularization.decided') {
        return '/hrms/attendance';
    }

    // A leave ask lands on the admin queue for the approver and on the
    // self-service history for the requester — each on the page that
    // actually shows the item, never a list-with-param the list ignores.
    if (type === 'hrms.leave.requested') {
        return '/hrms/leave';
    }

    if (type === 'hrms.leave.approved' || type === 'hrms.leave.rejected') {
        return '/hrms/leave/mine';
    }

    // Comp-off mirrors leave: the ask lands where the queue lives for the
    // approver and on the self-service bank for the requester.
    if (type === 'hrms.comp_off.requested') {
        return '/hrms/comp-off';
    }

    if (type === 'hrms.comp_off.approved' || type === 'hrms.comp_off.rejected') {
        return '/hrms/comp-off/mine';
    }

    // Expenses mirror leave: the filing lands on the queue for the approver,
    // every later step on the claimant's own history.
    if (type === 'hrms.expense.submitted') {
        return '/hrms/expenses';
    }

    if (type === 'hrms.expense.approved' || type === 'hrms.expense.rejected' || type === 'hrms.expense.paid') {
        return '/hrms/expenses/mine';
    }

    // Performance lands on the cycle for completions and on the
    // self-service page for acknowledgements — each where the reader can
    // see what changed, never a list-with-param the list ignores.
    if (type === 'hrms.performance.cycle_completed' && data.performance_cycle_id) {
        return `/hrms/performance/cycles/${data.performance_cycle_id}`;
    }

    if (type === 'hrms.performance.review_acknowledged') {
        return '/hrms/performance/mine';
    }

    // A finalized review lands on the self-service page beside the
    // acknowledgement receipt — each where the reader can see what sealed.
    if (type === 'talent.review_finalized') {
        return '/hrms/performance/mine';
    }

    // Clearance nudges land on the exit run; file decisions on the self-service
    // files page; a cycle opening on its detail; a shared review on mine.
    if (type === 'hrms.offboarding.clearance_pending' && data.offboarding_case_id) {
        return `/hrms/offboarding/cases/${data.offboarding_case_id}`;
    }

    if (type === 'hrms.document.verified' || type === 'hrms.document.rejected') {
        return '/hrms/documents/mine';
    }

    if (type === 'hrms.performance.cycle_opened' && data.performance_cycle_id) {
        return `/hrms/performance/cycles/${data.performance_cycle_id}`;
    }

    if (type === 'hrms.performance.review_shared') {
        return '/hrms/performance/mine';
    }

    // Hardware lands on the holder's own shelf; everything else on the
    // register — each where the reader can act, never a bare id.
    if (type === 'hrms.asset.assigned' || type === 'hrms.asset.return_overdue') {
        return '/hrms/assets/mine';
    }

    if (type === 'hrms.asset.acknowledged' || type === 'hrms.asset.returned') {
        return '/hrms/assets';
    }

    // Surveys land on the self-service answer flow — the one place an
    // invitee can act, since results sit behind the view permission.
    if (type === 'hrms.survey.invited' || type === 'hrms.survey.closing_soon') {
        return '/hrms/engagement/mine';
    }

    // Scheduled digests land on the dashboards they summarize — the counts
    // in the notification are the redacted tier, the charts behind the link
    // answer to the reader's own permissions.
    if (type === 'hrms.inbox.digest') {
        return '/hrms/analytics';
    }

    if (project_id && key) {
        const section = SECTION_BY_TYPE[type] ?? null;
        const extra = {};
        if (type === 'task.commented' && data?.comment_id) {
            extra.comment = data.comment_id;
        }
        return taskUrl(project_id, key, section, extra);
    }

    if (project_id) return projectUrl(project_id);
    if (workspace_id) return workspaceUrl(workspace_id);
    return '/';
}

export function timeAgo(value) {
    if (!value) return '';
    return formatRelativeTime(value, { fallback: '' });
}