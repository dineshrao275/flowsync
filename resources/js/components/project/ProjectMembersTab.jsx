import { useTranslation } from 'react-i18next';
import MemberPicker from '../../components/ui/MemberPicker';
import Avatar from '../../components/ui/Avatar';
import Card from '../../components/ui/Card';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import { projectRoleLabel } from '../../utils/roles';

export default function ProjectMembersTab({ addMember, candidateUsers, changeRole, formErrors, isProjectManager, memberForm, members, removeMember, roles, savingMember, setMemberForm, user }) {
    const { t } = useTranslation();

    return (
                <div className="space-y-5">
                    {isProjectManager && (
                        <Card title={t('tms.project.addMemberTitle')} subtitle={t('tms.project.addMemberSubtitle')}>
                            <form onSubmit={addMember} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="flex-1">
                                    <MemberPicker
                                        users={candidateUsers}
                                        value={memberForm.user_id}
                                        onChange={(id) => setMemberForm((f) => ({ ...f, user_id: id }))}
                                        placeholder={t('tms.project.searchPeople')}
                                    />
                                    {formErrors.user_id && <p className="mt-1.5 text-sm text-red-600">{formErrors.user_id}</p>}
                                </div>
                                <div>
                                    <select
                                        className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-[var(--accent)] focus:outline-none"
                                        value={memberForm.role_id}
                                        onChange={(e) => setMemberForm((f) => ({ ...f, role_id: Number(e.target.value) }))}
                                    >
                                        {roles.map((r) => (
                                            <option key={r.id} value={r.id}>
                                                {r.name}
                                            </option>
                                        ))}
                                    </select>
                                    {formErrors.role_id && <p className="mt-1.5 text-sm text-red-600">{formErrors.role_id}</p>}
                                </div>
                                <Button type="submit" loading={savingMember}>
                                    {t('tms.project.add')}
                                </Button>
                            </form>
                        </Card>
                    )}

                    <Card title={t('tms.project.membersTitle')}>
                        <ul className="divide-y divide-gray-100">
                            {members.map((member) => {
                                const self = member.id === user?.id;
                                const canManage = isProjectManager && !self;
                                return (
                                    <li key={member.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <div className="flex items-center gap-3">
                                            <Avatar name={member.name} photoUrl={member.photo_url} size="lg" />
                                            <div>
                                                <p className="text-sm font-medium text-gray-900">
                                                    {member.name} {self && <span className="text-gray-400">{t('tms.project.you')}</span>}
                                                </p>
                                                <p className="text-xs text-gray-400">{member.email}</p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {member.role ? (
                                                canManage ? (
                                                    <select
                                                        className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:outline-none"
                                                        value={member.role.id}
                                                        onChange={(e) => changeRole(member, Number(e.target.value))}
                                                    >
                                                        {roles.map((r) => (
                                                            <option key={r.id} value={r.id}>
                                                                {r.name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                ) : (
                                                    <Badge>{projectRoleLabel(member.role.slug, member.role.name)}</Badge>
                                                )
                                            ) : (
                                                <Badge>{t('tms.project.memberFallback')}</Badge>
                                            )}
                                            {canManage && (
                                                <Button size="sm" variant="ghost" onClick={() => removeMember(member)}>
                                                    {t('tms.project.remove')}
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
