<?php

namespace App\Services\Projects;

use App\Models\ProjectOpportunity;
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
