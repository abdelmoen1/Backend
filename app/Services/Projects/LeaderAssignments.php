<?php

namespace App\Services\Projects;

use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\ProjectApplication;
use App\Models\ProjectLeaderAssignment;
use App\Models\ProjectOpportunity;
use App\Models\ProjectTeam;
use App\Models\User;
use App\Services\Assessments\SkillVerification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * تعيين قائد الفريق بقرار مدير المنصة فقط، لمشروع بعينه. المؤسسة لا تختار القائد.
 *
 * فحوص الأهلية نوعان: "تعارض" لا يُتجاوز أبدًا (حساب غير طالب أو موقوف، أو متقدم/عضو في المشروع نفسه)،
 * و"توصية" يمكن لمدير المنصة تجاوزها بمبرر مكتوب يُحفظ مع التعيين (ملف غير مكتمل، لا مهارات مثبتة، عبء قيادة).
 */
class LeaderAssignments
{
    /**
     * @return array{eligible: bool, blocked: bool, checks: array<int, array{key: string, label: string, passed: bool, blocking: bool}>}
     */
    public function eligibility(User $candidate, ProjectOpportunity $opportunity): array
    {
        $student = $candidate->role === User::ROLE_STUDENT ? $candidate->studentProfile : null;
        $verified = 0;
        if ($student) {
            $practical = SkillVerification::practicalSkillIds();
            $verified = $student->skillAssessments()->get()
                ->groupBy('skill_id')
                ->filter(fn ($results): bool => SkillVerification::highestLevel($results, $practical) !== null)
                ->count();
        }
        $conflict = $student && (
            $opportunity->applications()->where('student_profile_id', $student->id)
                ->whereIn('status', ProjectApplication::ACTIVE_STATUSES)->exists()
            || $opportunity->teams()->where('status', '!=', ProjectTeam::STATUS_CANCELLED)
                ->whereHas('members', fn ($query) => $query->where('student_profile_id', $student->id))->exists()
        );
        $load = $candidate->leaderAssignments()->active()->where('project_opportunity_id', '!=', $opportunity->id)->count();
        $minHours = $opportunity->min_weekly_hours;

        $checks = [
            ['key' => 'student_account', 'label' => 'حساب طالب/خريج نشط', 'passed' => $student !== null && $candidate->isActive(), 'blocking' => true],
            ['key' => 'no_conflict', 'label' => 'ليس متقدمًا أو عضوًا في فريق هذا المشروع', 'passed' => ! $conflict, 'blocking' => true],
            ['key' => 'profile_complete', 'label' => 'أكمل ملفه الشخصي', 'passed' => (bool) $student?->onboarding_completed, 'blocking' => false],
            ['key' => 'verified_skills', 'label' => 'لديه مهارة مُثبتة بتقييم على الأقل', 'passed' => $verified >= max(1, (int) config('gotrainee.leader_min_verified_skills', 1)), 'blocking' => false],
            ['key' => 'leadership_load', 'label' => 'عبء القيادة الحالي ضمن الحد ('.config('gotrainee.max_active_leaderships', 2).' مشاريع)', 'passed' => $load < (int) config('gotrainee.max_active_leaderships', 2), 'blocking' => false],
            ['key' => 'availability', 'label' => 'ساعاته الأسبوعية تكفي متطلبات المشروع', 'passed' => $minHours === null || (int) $student?->weekly_availability_hours >= $minHours, 'blocking' => false],
        ];

        $blocked = collect($checks)->contains(fn (array $check): bool => $check['blocking'] && ! $check['passed']);

        return [
            'eligible' => collect($checks)->every(fn (array $check): bool => $check['passed']),
            'blocked' => $blocked,
            'verified_skills' => $verified,
            'active_leaderships' => $load,
            'checks' => $checks,
        ];
    }

    public function assign(ProjectOpportunity $opportunity, User $admin, int $candidateUserId, ?string $justification): ProjectLeaderAssignment
    {
        $justification = $justification !== null ? trim($justification) : null;

        try {
            [$assignment, $previous] = DB::transaction(function () use ($opportunity, $admin, $candidateUserId, $justification): array {
                // قفل صف المشروع يسلسل التعيينات المتزامنة؛ القيد الفريد على active_project_opportunity_id يحمي ما تبقى
                $project = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
                if ($project->status !== ProjectOpportunity::STATUS_PUBLISHED) {
                    throw new ProjectActionException('يُعيَّن قائد الفريق بعد الموافقة على المشروع ونشره.', 'project_not_published', 409);
                }
                if (in_array($project->project_status, [ProjectOpportunity::PROJECT_COMPLETED, ProjectOpportunity::PROJECT_CANCELLED], true)) {
                    throw new ProjectActionException('المشروع منتهٍ أو ملغى.', 'project_closed', 409);
                }

                $candidate = User::query()->with('studentProfile')->find($candidateUserId);
                if (! $candidate) {
                    throw new ProjectActionException('المستخدم غير موجود.', 'not_found', 404);
                }

                $current = $project->leaderAssignments()->active()->lockForUpdate()->first();
                if ($current && $current->leader_user_id === $candidate->id) {
                    return [$current, null];
                }

                $eligibility = $this->eligibility($candidate, $project);
                if ($eligibility['blocked']) {
                    throw new ProjectActionException('لا يمكن تعيين هذا المستخدم قائدًا لهذا المشروع.', 'leader_conflict', 422, ['eligibility' => $eligibility]);
                }
                if (! $eligibility['eligible'] && mb_strlen((string) $justification) < 15) {
                    throw new ProjectActionException('المرشح لا يستوفي كل معايير القيادة. اكتب مبررًا واضحًا (15 حرفًا على الأقل) لتجاوزها.', 'justification_required', 422, ['eligibility' => $eligibility]);
                }

                if ($current) {
                    $current->forceFill([
                        'active_project_opportunity_id' => null,
                        'revoked_at' => now(),
                        'revoked_by_user_id' => $admin->id,
                        'revocation_reason' => 'replaced',
                    ])->save();
                }

                $assignment = $project->leaderAssignments()->create([
                    'leader_user_id' => $candidate->id,
                    'active_project_opportunity_id' => $project->id,
                    'assigned_by_user_id' => $admin->id,
                    'assigned_at' => now(),
                    'eligibility' => $eligibility,
                    'override_justification' => $eligibility['eligible'] ? null : $justification,
                ]);

                $this->syncTeamLeader($project, $candidate);

                AuditLog::record($admin, $current ? 'project.leader.replaced' : 'project.leader.assigned', $project, [
                    'leader_user_id' => $candidate->id,
                    'previous_leader_user_id' => $current?->leader_user_id,
                    'override' => ! $eligibility['eligible'],
                    'justification' => $eligibility['eligible'] ? null : $justification,
                ]);

                return [$assignment, $current];
            });
        } catch (UniqueConstraintViolationException) {
            throw new ProjectActionException('تم تعيين قائد لهذا المشروع للتو. حدّث الصفحة وأعد المحاولة.', 'concurrent_assignment', 409);
        }

        if ($assignment->wasRecentlyCreated) {
            $this->notifyAssigned($assignment, $previous);
        }

        return $assignment;
    }

    public function revoke(ProjectOpportunity $opportunity, User $admin, string $reason): ProjectLeaderAssignment
    {
        $assignment = DB::transaction(function () use ($opportunity, $admin, $reason): ProjectLeaderAssignment {
            $project = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            $current = $project->leaderAssignments()->active()->lockForUpdate()->first();
            if (! $current) {
                throw new ProjectActionException('لا يوجد قائد معيّن لهذا المشروع.', 'no_leader', 409);
            }

            $current->forceFill([
                'active_project_opportunity_id' => null,
                'revoked_at' => now(),
                'revoked_by_user_id' => $admin->id,
                'revocation_reason' => $reason,
            ])->save();
            $this->syncTeamLeader($project, null);

            AuditLog::record($admin, 'project.leader.revoked', $project, ['leader_user_id' => $current->leader_user_id, 'reason' => $reason]);

            return $current;
        });

        PlatformNotification::send($assignment->leader,
            'project.leader.revoked',
            'انتهى تعيينك قائدًا للفريق',
            "ألغت إدارة المنصة تعيينك قائدًا لمشروع «{$opportunity->title}».",
            '/student/leading',
            ['project_id' => $opportunity->id],
        );

        return $assignment;
    }

    /** فريق المشروع القائم يعرض القائد المعيّن (للعرض فقط؛ الصلاحية من سجل التعيين) */
    private function syncTeamLeader(ProjectOpportunity $project, ?User $leader): void
    {
        $team = $project->currentTeam();
        if ($team) {
            $team->forceFill([
                'leader_student_profile_id' => $leader?->studentProfile?->id,
                'leader_assigned_at' => $leader ? now() : null,
            ])->save();
        }
    }

    private function notifyAssigned(ProjectLeaderAssignment $assignment, ?ProjectLeaderAssignment $previous): void
    {
        $project = $assignment->opportunity;
        PlatformNotification::send($assignment->leader,
            'project.leader.assigned',
            'تم تعيينك قائدًا لفريق مشروع',
            "عيّنتك إدارة المنصة قائدًا لفريق مشروع «{$project->title}». راجع المتقدمين وكوّن الفريق ثم اتفق على خطة التنفيذ مع المؤسسة.",
            "/student/leading/{$project->id}",
            ['project_id' => $project->id],
        );
        PlatformNotification::send($previous?->leader,
            'project.leader.replaced',
            'تم تغيير قائد الفريق',
            "عيّنت إدارة المنصة قائدًا آخر لمشروع «{$project->title}».",
            '/student/leading',
            ['project_id' => $project->id],
        );
        PlatformNotification::send($project->organization?->user,
            'project.leader.assigned',
            'تم تعيين قائد لفريق مشروعك',
            "عيّنت إدارة المنصة قائد فريق لمشروع «{$project->title}». يمكنكما الآن العمل على خطة التنفيذ.",
            "/organization/projects/{$project->id}",
            ['project_id' => $project->id],
        );
    }
}
