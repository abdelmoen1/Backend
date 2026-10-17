<?php

namespace App\Services\Projects;

use App\Models\PlatformNotification;
use App\Models\ProjectApplication;
use App\Models\ProjectOpportunity;
use App\Models\ProjectTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تكوين فريق المشروع بيد قائد الفريق الذي عيّنه مدير المنصة (القائد ليس عضوًا في الفريق ولا يُختار من المتقدمين). كل عضو يُختار من طلب انضمام قائم لهذا المشروع نفسه، ويجب أن يستوفي الآن الدور
 * الذي يُعيَّن له (قواعد OpportunityMatcher)، دون تكرار، وبعدد لا يتجاوز العدد المطلوب لكل دور.
 * الفريق قيد التكوين (forming) يمكن تعديل أعضائه؛ بعد التأكيد (ready) يُقفل التكوين.
 */
class TeamFormation
{
    public function __construct(private readonly OpportunityMatcher $matcher) {}

    /**
     * يحفظ تشكيلة الفريق كاملة (إنشاء أو تعديل): الأعضاء غير المذكورين يُزالون ويعود طلبهم إلى "تمت المراجعة".
     *
     * @param  array<int, array{application_id: int, role_id: int}>  $members
     */
    public function save(ProjectOpportunity $opportunity, User $actor, array $members): ProjectTeam
    {
        return DB::transaction(function () use ($opportunity, $actor, $members): ProjectTeam {
            $project = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($project->status !== ProjectOpportunity::STATUS_PUBLISHED || ! in_array($project->project_status, ProjectOpportunity::TEAM_STAGES, true)) {
                throw new ProjectActionException('لا يمكن تكوين فريق لهذا المشروع في حالته الحالية.', 'project_not_ready', 409);
            }

            $team = $project->currentTeam();
            if ($team && $team->status !== ProjectTeam::STATUS_FORMING) {
                throw new ProjectActionException('تم تأكيد الفريق، لذلك لا يمكن تعديل أعضائه.', 'team_locked', 409);
            }

            $roles = $project->activeVersion()->with('roles.skills.skill:id,name')->first()->roles->keyBy('id');
            $applicationIds = array_column($members, 'application_id');
            if (count($applicationIds) !== count(array_unique($applicationIds))) {
                throw new ProjectActionException('تم اختيار الطالب نفسه أكثر من مرة.', 'duplicate_member');
            }

            // الطلبات من هذا المشروع فقط؛ أي معرّف من مشروع آخر يُرفض دون كشف وجوده
            $applications = $project->applications()
                ->whereIn('id', $applicationIds)
                ->with('studentProfile.user:id,name')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $leaderUserId = $project->activeLeaderAssignment()->value('leader_user_id');
            $filled = [];
            foreach ($members as $member) {
                $application = $applications->get($member['application_id']);
                if (! $application) {
                    throw new ProjectActionException('أحد الطلاب المختارين ليس ضمن المتقدمين لهذا المشروع.', 'not_in_pool');
                }
                $name = $application->studentProfile->user?->name ?? 'الطالب';
                if ($leaderUserId !== null && (int) $application->studentProfile->user_id === (int) $leaderUserId) {
                    throw new ProjectActionException('قائد الفريق لا يُضم عضوًا في فريق المشروع الذي يقوده.', 'leader_not_member');
                }
                if (! $application->isActive()) {
                    throw new ProjectActionException("طلب {$name} مسحوب أو مرفوض، فلا يمكن ضمه إلى الفريق.", 'application_inactive');
                }
                $role = $roles->get($member['role_id']);
                if (! $role) {
                    throw new ProjectActionException('الدور المختار ليس من أدوار هذا المشروع.', 'unknown_role');
                }
                if (! in_array($role->id, $this->matcher->eligibleRoleIds($application->studentProfile, $project), true)) {
                    throw new ProjectActionException("{$name} لا يستوفي حاليًا متطلبات دور {$role->title}.", 'not_eligible');
                }
                $filled[$role->id] = ($filled[$role->id] ?? 0) + 1;
                if ($filled[$role->id] > $role->headcount) {
                    throw new ProjectActionException("دور {$role->title} يحتاج {$role->headcount} فقط.", 'role_full');
                }
            }

            if ($members === []) {
                throw new ProjectActionException('اختر عضوًا واحدًا على الأقل لتكوين الفريق.', 'empty_team');
            }

            $created = ! $team;
            $team ??= $project->teams()->create([
                'status' => ProjectTeam::STATUS_FORMING,
                'created_by_user_id' => $actor->id,
                'status_changed_at' => now(),
                'leader_student_profile_id' => $actor->studentProfile?->id,
                'leader_assigned_at' => now(),
            ]);

            $keep = collect($members)->keyBy(fn (array $member): int => $applications[$member['application_id']]->student_profile_id);
            $removed = [];
            foreach ($team->members()->get() as $existing) {
                if (! $keep->has($existing->student_profile_id)) {
                    $removed[] = $existing->student_profile_id;
                    $existing->delete();
                }
            }
            foreach ($keep as $studentId => $member) {
                $team->members()->updateOrCreate(
                    ['student_profile_id' => $studentId],
                    ['project_role_id' => $member['role_id'], 'project_application_id' => $member['application_id']]
                );
            }

            ProjectApplication::query()
                ->whereIn('id', $applicationIds)
                ->where('status', '!=', ProjectApplication::STATUS_ACCEPTED)
                ->update(['status' => ProjectApplication::STATUS_ACCEPTED, 'status_changed_at' => now()]);
            if ($removed !== []) {
                $project->applications()
                    ->whereIn('student_profile_id', $removed)
                    ->where('status', ProjectApplication::STATUS_ACCEPTED)
                    ->update(['status' => ProjectApplication::STATUS_REVIEWED, 'status_changed_at' => now()]);
            }

            if ($team->leader_student_profile_id !== null && in_array($team->leader_student_profile_id, $removed, true)) {
                $team->forceFill(['leader_student_profile_id' => null, 'leader_assigned_at' => null, 'leader_assigned_by_user_id' => null])->save();
            }

            if ($project->project_status !== ProjectOpportunity::PROJECT_TEAM_FORMATION) {
                $project->forceFill(['project_status' => ProjectOpportunity::PROJECT_TEAM_FORMATION, 'status_changed_at' => now()])->save();
            }

            Log::info($created ? 'Project team created.' : 'Project team members updated.', [
                'team_id' => $team->id,
                'opportunity_id' => $project->id,
                'members' => $keep->keys()->all(),
                'removed' => $removed,
                'by_user_id' => $actor->id,
            ]);

            return $team->refresh();
        });
    }

    /** تأكيد الفريق (forming → ready): يحتاج قائدًا معيّنًا من مدير المنصة، وكل عضو ما زال يستوفي دوره. */
    public function confirm(ProjectTeam $team, User $actor): ProjectTeam
    {
        return DB::transaction(function () use ($team, $actor): ProjectTeam {
            $record = ProjectTeam::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== ProjectTeam::STATUS_FORMING) {
                throw new ProjectActionException('الفريق مؤكد بالفعل.', 'already_confirmed', 409);
            }
            $members = $record->members()->with(['studentProfile.user:id,name', 'role:id,title'])->get();
            if ($members->isEmpty()) {
                throw new ProjectActionException('أضف أعضاء إلى الفريق قبل تأكيده.', 'empty_team');
            }
            $project = $record->opportunity;
            if (! $project->activeLeaderAssignment()->exists()) {
                throw new ProjectActionException('لا يوجد قائد فريق معيّن لهذا المشروع.', 'leader_required');
            }

            foreach ($members as $member) {
                if (! in_array($member->project_role_id, $this->matcher->eligibleRoleIds($member->studentProfile, $project), true)) {
                    $name = $member->studentProfile->user?->name ?? 'أحد الأعضاء';
                    throw new ProjectActionException("{$name} لم يعد يستوفي متطلبات دور {$member->role->title}. عدّل الفريق ثم أكده.", 'not_eligible');
                }
            }

            $record->forceFill(['status' => ProjectTeam::STATUS_READY, 'status_changed_at' => now()])->save();

            Log::info('Project team confirmed.', [
                'team_id' => $record->id,
                'opportunity_id' => $record->project_opportunity_id,
                'members' => $members->pluck('student_profile_id')->all(),
                'leader_student_profile_id' => $record->leader_student_profile_id,
                'by_user_id' => $actor->id,
            ]);

            DB::afterCommit(function () use ($members, $project): void {
                foreach ($members as $member) {
                    PlatformNotification::send($member->studentProfile?->user,
                        'team.confirmed',
                        'تم قبولك في فريق مشروع',
                        "أكّد قائد الفريق انضمامك إلى «{$project->title}» بدور {$member->role?->title}. ستظهر مهامك عند بدء التنفيذ.",
                        "/student/projects/{$project->id}",
                        ['project_id' => $project->id],
                    );
                }
            });

            return $record;
        });
    }
}
