import { useTranslation } from 'react-i18next';
import Card from '../ui/Card';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Avatar from '../ui/Avatar';
import MemberPicker from '../ui/MemberPicker';

export function WorkspaceMembersTab({
    isManager,
    candidateUsers,
    memberForm,
    setMemberForm,
    formErrors,
    roleOptions,
    savingMember,
    addMember,
    members,
    user,
    changeRole,
    removeMember,
}) {
    const { t } = useTranslation();

    return (
        <div className="space-y-5">
            {isManager && (
                <Card title={t('tms.workspaceDetail.addMemberTitle')} subtitle={t('tms.workspaceDetail.addMemberSubtitle')}>
                    <form onSubmit={addMember} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="flex-1">
                            <MemberPicker
                                users={candidateUsers}
                                value={memberForm.user_id}
                                onChange={(id) => setMemberForm((f) => ({ ...f, user_id: id }))}
                                placeholder={t('tms.workspaceDetail.searchPeople')}
                            />
                            {formErrors.user_id && <p className="mt-1.5 text-sm text-red-600">{formErrors.user_id}</p>}
                        </div>
                        <div>
                            <select
                                className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-[var(--accent)] focus:outline-none"
                                value={memberForm.role}
                                onChange={(e) => setMemberForm((f) => ({ ...f, role: e.target.value }))}
                            >
                                {roleOptions.map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {t(r.labelKey)}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button type="submit" loading={savingMember}>
                            {t('tms.workspaceDetail.add')}
                        </Button>
                    </form>
                </Card>
            )}

            <Card title={t('tms.workspaceDetail.membersTitle')}>
                <ul className="divide-y divide-gray-100">
                    {members.map((member) => {
                        const self = member.id === user?.id;
                        const canDemote = isManager && member.role !== 'owner' && !self;
                        const canRemove = isManager && member.role !== 'owner';
                        return (
                            <li key={member.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div className="flex items-center gap-3">
                                    <Avatar name={member.name} photoUrl={member.photo_url} size="lg" />
                                    <div>
                                        <p className="text-sm font-medium text-gray-900">
                                            {member.name} {self && <span className="text-gray-400">{t('tms.workspaceDetail.you')}</span>}
                                        </p>
                                        <p className="text-xs text-gray-400">{member.email}</p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Badge>{member.role}</Badge>
                                    {canDemote && (
                                        <select
                                            className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:outline-none"
                                            value={member.role}
                                            onChange={(e) => changeRole(member, e.target.value)}
                                        >
                                            {roleOptions.map((r) => (
                                                <option key={r.value} value={r.value}>
                                                    {t(r.labelKey)}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    {canRemove && (
                                        <Button size="sm" variant="ghost" onClick={() => removeMember(member)}>
                                            {t('tms.workspaceDetail.remove')}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </Card>
        </div>
    );
}
