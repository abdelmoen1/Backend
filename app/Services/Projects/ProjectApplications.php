<?php

namespace App\Services\Projects;

use App\Models\ProjectApplication;
use App\Models\ProjectOpportunity;
use App\Models\ProjectTeam;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * طلبات الانضمام. الطالب يقدّم لنفسه فقط (الملف من الجلسة لا من الطلب)، على مشروع منشور مفتوح للتقديم، مرة واحدة
 * (قيد فريد + قفل)، وبشرط أن يستوفي الآن دورًا واحدًا على الأقل بالقواعد نفسها التي يراها في صفحة الفرصة.
 */
class ProjectApplications
{
    /** انتقالات الحالة التي تملكها المؤسسة؛ accepted يُضبط عند ضم الطالب للفريق فقط */
    private const REVIEW_TRANSITIONS = [
        ProjectApplication::STATUS_PENDING => [ProjectApplication::STATUS_REVIEWED, ProjectApplication::STATUS_REJECTED],
        ProjectApplication::STATUS_REVIEWED => [ProjectApplication::STATUS_REJECTED],
        ProjectApplication::STATUS_REJECTED => [ProjectApplication::STATUS_REVIEWED],
    ];

    public function __construct(private readonly OpportunityMatcher $matcher) {}

    public function apply(StudentProfile $student, int $opportunityId, ?int $preferredRoleId, ?string $note): ProjectApplication
    {
        try {
            return DB::transaction(function () use ($student, $opportunityId, $preferredRoleId, $note): ProjectApplication {
                $opportunity = ProjectOpportunity::query()
                    ->published()
                    ->whereKey($opportunityId)
                    ->lockForUpdate()
                    ->first();
                if (! $opportunity) {
                    throw new ProjectActionException('هذه الفرصة غير متاحة حاليًا.', 'not_found', 404);
                }

                $existing = $opportunity->applications()->where('student_profile_id', $student->id)->first();
                if ($existing) {
                    throw new ProjectActionException('سبق أن قدّمت على هذه الفرصة. يمكنك متابعة حالة طلبك من صفحة طلباتي.', 'already_applied', 409, ['application' => $existing]);
                }

                if (! $opportunity->acceptsApplications()) {
                    throw new ProjectActionException('التقديم على هذه الفرصة مغلق حاليًا.', 'closed', 409);
                }

                $eligible = $this->matcher->eligibleRoleIds($student, $opportunity);
                if ($eligible === []) {
                    throw new ProjectActionException('لا تستوفي حاليًا متطلبات أي دور في هذه الفرصة. راجع ما ينقصك في صفحة الفرصة ثم قدّم.', 'not_eligible', 422);
                }
                if ($preferredRoleId !== null && ! in_array($preferredRoleId, $eligible, true)) {
                    $known = $opportunity->activeVersion->roles->contains('id', $preferredRoleId);
                    throw new ProjectActionException(
                        $known ? 'لا تستوفي متطلبات الدور الذي اخترته. اختر دورًا تستوفيه.' : 'الدور المختار ليس من أدوار هذه الفرصة.',
                        'role_not_eligible',
                        422
                    );
                }

                $application = $opportunity->applications()->create([
                    'student_profile_id' => $student->id,
                    'preferred_role_id' => $preferredRoleId ?? (count($eligible) === 1 ? $eligible[0] : null),
                    'status' => ProjectApplication::STATUS_PENDING,
                    'note' => $note,
                    'status_changed_at' => now(),
                ]);

                Log::info('Project application submitted.', [
                    'application_id' => $application->id,
                    'opportunity_id' => $opportunity->id,
                    'student_profile_id' => $student->id,
                    'preferred_role_id' => $application->preferred_role_id,
                ]);

                return $application;
            });
        } catch (UniqueConstraintViolationException) {
            // طلبان متزامنان: القيد الفريد في قاعدة البيانات هو الحاجز الأخير
            throw new ProjectActionException('سبق أن قدّمت على هذه الفرصة.', 'already_applied', 409);
        }
    }

    /** سحب الطالب لطلبه؛ إن كان ضمن فريق ما زال قيد التكوين يُزال منه (ومن القيادة إن كان قائدًا). */
    public function withdraw(StudentProfile $student, int $applicationId): ProjectApplication
    {
        return DB::transaction(function () use ($student, $applicationId): ProjectApplication {
            $application = $student->applications()->whereKey($applicationId)->lockForUpdate()->first();
            if (! $application) {
                throw new ProjectActionException('الطلب غير موجود.', 'not_found', 404);
            }
            if (! $application->isActive()) {
                throw new ProjectActionException('لا يمكن سحب طلب مسحوب أو مرفوض.', 'invalid_status', 409);
            }

            $team = ProjectTeam::query()
                ->where('project_opportunity_id', $application->project_opportunity_id)
                ->where('status', '!=', ProjectTeam::STATUS_CANCELLED)
                ->lockForUpdate()
                ->first();
            $membership = $team?->members()->where('student_profile_id', $student->id)->first();
            if ($membership) {
                if ($team->status !== ProjectTeam::STATUS_FORMING) {
                    throw new ProjectActionException('أنت عضو في فريق تم تأكيده. تواصل مع المؤسسة لترك الفريق.', 'team_locked', 409);
                }
                $membership->delete();
                if ($team->leader_student_profile_id === $student->id) {
                    $team->forceFill(['leader_student_profile_id' => null, 'leader_assigned_at' => null, 'leader_assigned_by_user_id' => null])->save();
                    Log::warning('Team leader withdrew; leader cleared.', ['team_id' => $team->id, 'student_profile_id' => $student->id]);
                }
            }

            $application->forceFill(['status' => ProjectApplication::STATUS_WITHDRAWN, 'status_changed_at' => now()])->save();

            Log::info('Project application withdrawn.', [
                'application_id' => $application->id,
                'opportunity_id' => $application->project_opportunity_id,
                'student_profile_id' => $student->id,
                'removed_from_team' => (bool) $membership,
            ]);

            return $application;
        });
    }

    /** مراجعة المؤسسة: reviewed أو rejected (أو التراجع عن الرفض). */
    public function review(ProjectOpportunity $opportunity, int $applicationId, string $status, User $actor): ProjectApplication
    {
        return DB::transaction(function () use ($opportunity, $applicationId, $status, $actor): ProjectApplication {
            $application = $opportunity->applications()->whereKey($applicationId)->lockForUpdate()->first();
            if (! $application) {
                throw new ProjectActionException('الطلب غير موجود في هذا المشروع.', 'not_found', 404);
            }
            if ($application->status === ProjectApplication::STATUS_ACCEPTED) {
                throw new ProjectActionException('هذا الطالب ضمن الفريق. أزله من الفريق أولًا ثم غيّر حالة طلبه.', 'in_team', 409);
            }
            if (! in_array($status, self::REVIEW_TRANSITIONS[$application->status] ?? [], true)) {
                throw new ProjectActionException('لا يمكن تغيير حالة هذا الطلب بهذه الطريقة.', 'invalid_transition', 409);
            }

            $previous = $application->status;
            $application->forceFill(['status' => $status, 'status_changed_at' => now()])->save();

            Log::info('Project application reviewed.', [
                'application_id' => $application->id,
                'opportunity_id' => $opportunity->id,
                'from' => $previous,
                'to' => $status,
                'by_user_id' => $actor->id,
            ]);

            return $application;
        });
    }
}
