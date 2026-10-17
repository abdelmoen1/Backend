<?php

namespace App\Services\Projects;

use App\Models\ProjectOpportunity;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\User;

/**
 * من يصل إلى مشروع بعينه وبأي صفة. أي مشروع لا علاقة للمستخدم به = 404 دون كشف وجوده.
 * - leader: قائد الفريق الساري المعيّن من مدير المنصة (يدير المتقدمين والفريق ويكتب الخطة).
 * - organization: مالك المؤسسة صاحبة المشروع (عرض للقراءة، ومراجعة الخطة والموافقة عليها).
 * - admin: مدير المنصة (قراءة وإشراف).
 */
class ProjectAccess
{
    public const LEADER = 'leader';

    public const ORGANIZATION = 'organization';

    public const ADMIN = 'admin';

    /** عضو في فريق المشروع (مساحة التنفيذ فقط: مهامه وتسليماته) */
    public const MEMBER = 'member';

    public function asLeader(User $user, int $projectId): ProjectOpportunity
    {
        $project = $user->role === User::ROLE_STUDENT
            ? ProjectOpportunity::query()
                ->whereKey($projectId)
                ->whereHas('leaderAssignments', fn ($query) => $query->active()->where('leader_user_id', $user->id))
                ->first()
            : null;
        abort_unless($project, 404, 'المشروع غير موجود أو لست قائد فريقه.');

        return $project;
    }

    public function asOrganization(User $user, int $projectId): ProjectOpportunity
    {
        $profile = $user->role === User::ROLE_ORGANIZATION ? $user->organizationProfile : null;
        $project = $profile
            ? ProjectOpportunity::query()->where('organization_id', $profile->id)->whereKey($projectId)->first()
            : null;
        abort_unless($project, 404, 'المشروع غير موجود.');

        return $project;
    }

    /**
     * عضو في فريق المشروع القائم أو المكتمل (لا الملغى). يُرجع المشروع وعضويته.
     *
     * @return array{0: ProjectOpportunity, 1: ProjectTeamMember}
     */
    public function asMember(User $user, int $projectId): array
    {
        $membership = $user->role === User::ROLE_STUDENT && $user->studentProfile
            ? ProjectTeamMember::query()
                ->where('student_profile_id', $user->studentProfile->id)
                ->whereHas('team', fn ($query) => $query->where('project_opportunity_id', $projectId)->where('status', '!=', ProjectTeam::STATUS_CANCELLED))
                ->with('team.opportunity')
                ->latest('id')
                ->first()
            : null;
        abort_unless($membership, 404, 'المشروع غير موجود أو لست عضوًا في فريقه.');

        return [$membership->team->opportunity, $membership];
    }

    /**
     * أطراف مساحة التنفيذ: القائد والأعضاء والمؤسسة ومدير المنصة. member يحمل العضوية، والبقية null.
     *
     * @return array{0: ProjectOpportunity, 1: string, 2: ?ProjectTeamMember}
     */
    public function executionParticipant(User $user, int $projectId): array
    {
        if ($user->role === User::ROLE_STUDENT && ! ProjectOpportunity::query()->whereKey($projectId)
            ->whereHas('leaderAssignments', fn ($query) => $query->active()->where('leader_user_id', $user->id))->exists()) {
            [$project, $membership] = $this->asMember($user, $projectId);

            return [$project, self::MEMBER, $membership];
        }

        return [...$this->participant($user, $projectId), null];
    }

    /** @return array{0: ProjectOpportunity, 1: string} */
    public function participant(User $user, int $projectId): array
    {
        if ($user->isAdmin()) {
            $project = ProjectOpportunity::query()->find($projectId);
            abort_unless($project, 404, 'المشروع غير موجود.');

            return [$project, self::ADMIN];
        }
        if ($user->role === User::ROLE_ORGANIZATION) {
            return [$this->asOrganization($user, $projectId), self::ORGANIZATION];
        }

        return [$this->asLeader($user, $projectId), self::LEADER];
    }
}
