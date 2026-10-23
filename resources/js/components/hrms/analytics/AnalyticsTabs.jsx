import { useTranslation } from 'react-i18next';
import Card from '../../ui/Card';
import { Table, Th, Td, TableEmpty } from '../../ui/Table';
import { Tiles, HBar } from '../AnalyticsWidgets';
import { formatMinutes } from '../../../utils/time';

export function mapOf(record) {
    return Object.entries(record ?? {}).map(([name, value]) => ({ name, value: Number(value) || 0 }));
}

export function OverviewTab({ payload }) {
    const { t } = useTranslation();
    const headcount = payload.headcount ?? {};
    const attendance = payload.attendance ?? {};
    const leave = payload.leave ?? {};
    const lifecycle = payload.lifecycle ?? {};
    const performance = payload.performance ?? {};

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: t('hrms.analytics.tiles.headcount'), value: headcount.total },
                    { label: t('hrms.analytics.tiles.newHiresThisMonth'), value: headcount.new_hires_this_month },
                    { label: t('hrms.analytics.tiles.exitsThisMonth'), value: headcount.exits_this_month },
                    { label: t('hrms.analytics.tiles.attrition3mo'), value: headcount.attrition_3mo_percent != null ? `${headcount.attrition_3mo_percent}%` : null },
                    { label: t('hrms.analytics.tiles.presentDays'), value: attendance.present_days, hint: t('hrms.analytics.hints.currentWindow') },
                    { label: t('hrms.analytics.tiles.absentDays'), value: attendance.absent_days, hint: t('hrms.analytics.hints.currentWindow') },
                    { label: t('hrms.analytics.tiles.leavePending'), value: leave.pending_approvals },
                    { label: t('hrms.analytics.tiles.onboardingDone'), value: lifecycle.onboarding_completion_percent != null ? `${lifecycle.onboarding_completion_percent}%` : null },
                    { label: t('hrms.analytics.tiles.reviewsComplete'), value: performance.review_completion_percent != null ? `${performance.review_completion_percent}%` : null },
                ]}
            />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title={t('hrms.analytics.cards.headcountByDept')} subtitle={t('hrms.analytics.cards.activePerTeam')}>
                    <HBar data={mapOf(headcount.by_department)} emptyHint={t('hrms.analytics.empties.noDepartments')} />
                </Card>
                <Card title={t('hrms.analytics.cards.leaveByType')} subtitle={t('hrms.analytics.cards.approvedDaysPerType')}>
                    <HBar
                        data={(leave.by_type ?? []).map((row) => ({ name: row.type, value: Number(row.days) || 0 }))}
                        color="#059669"
                        emptyHint={t('hrms.analytics.empties.noApprovedLeave')}
                    />
                </Card>
            </div>
        </div>
    );
}

export function AttendanceTab({ payload }) {
    const { t } = useTranslation();

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: t('hrms.analytics.tiles.presentDays'), value: payload.present_days },
                    { label: t('hrms.analytics.tiles.absentDays'), value: payload.absent_days },
                    { label: t('hrms.analytics.tiles.lateDays'), value: payload.late_days },
                    { label: t('hrms.analytics.tiles.leaveDays'), value: payload.leave_days },
                    { label: t('hrms.analytics.tiles.avgWorked'), value: payload.average_worked_hours != null ? `${payload.average_worked_hours}h` : null },
                    { label: t('hrms.analytics.tiles.overtime'), value: formatMinutes(payload.overtime_minutes) },
                ]}
            />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title={t('hrms.analytics.cards.mostLate')} subtitle={t('hrms.analytics.cards.top5LateMinutes')}>
                    <HBar
                        data={(payload.top_late ?? []).map((row) => ({ name: row.name, value: Number(row.minutes) || 0 }))}
                        color="#d97706"
                        format={(value) => formatMinutes(value)}
                        emptyHint={t('hrms.analytics.empties.nobodyLate')}
                    />
                </Card>
                <Card title={t('hrms.analytics.cards.mostOvertime')} subtitle={t('hrms.analytics.cards.top5OvertimeMinutes')}>
                    <HBar
                        data={(payload.top_overtime ?? []).map((row) => ({ name: row.name, value: Number(row.minutes) || 0 }))}
                        color="#2563eb"
                        format={(value) => formatMinutes(value)}
                        emptyHint={t('hrms.analytics.empties.noOvertime')}
                    />
                </Card>
            </div>
        </div>
    );
}

export function LeaveTab({ payload }) {
    const { t } = useTranslation();
    const pending = payload.pending_items ?? [];

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: t('hrms.analytics.tiles.pendingApprovals'), value: payload.pending_approvals },
                    { label: t('hrms.analytics.tiles.expiryLiability'), value: payload.expiry_liability ?? t('hrms.analytics.withheld'), hint: payload.expiry_liability == null ? t('hrms.analytics.hints.needsPayrollRun') : t('hrms.analytics.hints.estimate') },
                ]}
            />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title={t('hrms.analytics.cards.leaveByType')} subtitle={t('hrms.analytics.cards.approvedDaysPerType')}>
                    <HBar
                        data={(payload.by_type ?? []).map((row) => ({ name: row.type, value: Number(row.days) || 0 }))}
                        color="#059669"
                        emptyHint={t('hrms.analytics.empties.noApprovedLeave')}
                    />
                </Card>
                <Card title={t('hrms.analytics.cards.topConsumers')} subtitle={t('hrms.analytics.cards.top5ApprovedDays')}>
                    <HBar
                        data={(payload.top_consumers ?? []).map((row) => ({ name: row.name, value: Number(row.days) || 0 }))}
                        emptyHint={t('hrms.analytics.empties.noApprovedLeave')}
                    />
                </Card>
            </div>
            <Card title={t('hrms.analytics.cards.pendingRequests')} subtitle={t('hrms.analytics.cards.oldestTen')}>
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('hrms.analytics.table.employee')}</Th>
                            <Th>{t('hrms.analytics.table.from')}</Th>
                            <Th>{t('hrms.analytics.table.to')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {pending.length === 0 ? (
                            <TableEmpty colSpan={3}>{t('hrms.analytics.empties.nothingAwaiting')}</TableEmpty>
                        ) : (
                            pending.map((row) => (
                                <tr key={row.id}>
                                    <Td>{row.employee}</Td>
                                    <Td>{row.from_date}</Td>
                                    <Td>{row.to_date}</Td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </Table>
            </Card>
        </div>
    );
}

export function LifecycleTab({ payload }) {
    const { t } = useTranslation();

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: t('hrms.analytics.tiles.onboardingAvg'), value: payload.onboarding_avg_days != null ? t('hrms.analytics.days', { count: payload.onboarding_avg_days }) : null },
                    { label: t('hrms.analytics.tiles.offboardingAvg'), value: payload.offboarding_avg_days != null ? t('hrms.analytics.days', { count: payload.offboarding_avg_days }) : null },
                    { label: t('hrms.analytics.tiles.onboardingDone'), value: payload.onboarding_completion_percent != null ? `${payload.onboarding_completion_percent}%` : null },
                    { label: t('hrms.analytics.tiles.offboardingDone'), value: payload.offboarding_completion_percent != null ? `${payload.offboarding_completion_percent}%` : null },
                    { label: t('hrms.analytics.tiles.timeToFirstDay'), value: payload.time_to_first_day_avg != null ? t('hrms.analytics.days', { count: payload.time_to_first_day_avg }) : null },
                ]}
            />
            <Card title={t('hrms.analytics.cards.checklistCompletion')} subtitle={t('hrms.analytics.cards.shareMandatory')}>
                <HBar
                    data={[
                        { name: t('hrms.analytics.onboarding'), value: Number(payload.onboarding_completion_percent) || 0 },
                        { name: t('hrms.analytics.offboarding'), value: Number(payload.offboarding_completion_percent) || 0 },
                    ]}
                    color="#059669"
                    format={(value) => `${value}%`}
                />
            </Card>
        </div>
    );
}

export function PerformanceTab({ payload }) {
    const { t } = useTranslation();
    const ratings = payload.ratings ?? null;
    const goals = Object.entries(payload.goals_by_status ?? {}).reduce((sum, [, count]) => sum + Number(count), 0);

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: t('hrms.analytics.tiles.goalsTracked'), value: goals },
                    { label: t('hrms.analytics.tiles.reviewsComplete'), value: payload.review_completion_percent != null ? `${payload.review_completion_percent}%` : null },
                ]}
            />
            <Card title={t('hrms.analytics.cards.goalsByStatus')} subtitle={t('hrms.analytics.cards.countsOnly')}>
                <HBar data={mapOf(payload.goals_by_status)} emptyHint={t('hrms.analytics.empties.noGoals')} />
            </Card>
            {ratings && (
                <Card title={t('hrms.analytics.cards.managerRatings')} subtitle={t('hrms.analytics.cards.nonPeerCycles')}>
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.analytics.table.cycle')}</Th>
                                <Th>{t('hrms.analytics.table.average')}</Th>
                                <Th>{t('hrms.analytics.table.reviews')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {ratings.length === 0 ? (
                                <TableEmpty colSpan={3}>{t('hrms.analytics.empties.noSharedRatings')}</TableEmpty>
                            ) : (
                                ratings.map((row) => (
                                    <tr key={row.cycle}>
                                        <Td>{row.cycle}</Td>
                                        <Td>{row.average_manager_rating}</Td>
                                        <Td>{row.reviews}</Td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </Table>
                </Card>
            )}
        </div>
    );
}

export function PayrollTab({ payload }) {
    const { t } = useTranslation();

    return (
        <div className="space-y-6">
            <Tiles
                items={[
                    { label: t('hrms.analytics.tiles.totalGross'), value: payload.total_gross },
                    { label: t('hrms.analytics.tiles.totalNet'), value: payload.total_net },
                    { label: t('hrms.analytics.tiles.annualCtc'), value: payload.total_annual_ctc, hint: t('hrms.analytics.hints.trailing365') },
                ]}
            />
            <Card title={t('hrms.analytics.cards.costByDept')} subtitle={t('hrms.analytics.cards.annualCtcFromStructures')}>
                <HBar
                    data={(payload.department_cost ?? []).map((row) => ({ name: row.department, value: Number(row.annual_ctc) || 0 }))}
                    color="#059669"
                    emptyHint={t('hrms.analytics.empties.noSalaryStructures')}
                />
            </Card>
        </div>
    );
}
