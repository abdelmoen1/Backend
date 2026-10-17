<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectApplication;
use App\Models\ProjectOpportunity;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Services\Projects\CandidateRanking;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\ProjectActionException;
use App\Services\Projects\ProjectApplications;
use App\Services\Projects\ProjectStaffing;
use App\Services\Projects\TeamFormation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * إدارة المتقدمين والفريق لقائد الفريق الساري للمشروع (يعيّنه مدير المنصة): المرشحون المرتبون، فتح/إغلاق
 * التقديم، مراجعة الطلبات، تكوين الفريق وتأكيده. المؤسسة لا تصل إلى هذه المسارات ولا إلى ترتيب المرشحين أو
 * نتائج تقييماتهم؛ لها عرض للقراءة فقط في OrganizationProjectController.
 * كل معرّف (مشروع أو فريق) يُبحث عنه ضمن المشاريع التي يقودها المستخدم فقط؛ غير ذلك = 404 دون كشف وجوده.
 */
class ProjectTeamController extends Controller
{
    public function matches(Request $request, int $project, CandidateRanking $ranking): JsonResponse
    {
        $opportunity = $this->ownedProject($request, $project);
        $result = $ranking->forProject($opportunity);

        return response()->json([
            'success' => true,
            'data' => [
                'project' => $this->project($opportunity),
                'team' => $this->team($opportunity->currentTeam()),
                'weights' => CandidateRanking::WEIGHTS,
            ] + $result,
        ]);
    }

    public function update(Request $request, int $project): JsonResponse
    {
        $opportunity = $this->ownedProject($request, $project);
        $validated = $request->validate([
            'project_status' => ['sometimes', Rule::in(ProjectOpportunity::PROJECT_STATUSES)],
            'min_weekly_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:60'],
        ]);

        return DB::transaction(function () use ($opportunity, $validated, $request): JsonResponse {
            $record = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            $changes = [];

            if (array_key_exists('project_status', $validated) && $validated['project_status'] !== $record->project_status) {
                if (! in_array($validated['project_status'], ProjectOpportunity::MANUAL_TRANSITIONS[$record->project_status] ?? [], true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'لا يمكن نقل المشروع إلى هذه المرحلة من مرحلته الحالية.',
                        'reason' => 'invalid_transition',
                    ], 409);
                }
                $changes['project_status'] = [$record->project_status, $validated['project_status']];
                $record->project_status = $validated['project_status'];
                $record->status_changed_at = now();
            }
            if (array_key_exists('min_weekly_hours', $validated) && $validated['min_weekly_hours'] !== $record->min_weekly_hours) {
                $changes['min_weekly_hours'] = [$record->min_weekly_hours, $validated['min_weekly_hours']];
                $record->min_weekly_hours = $validated['min_weekly_hours'];
            }

            if ($changes !== []) {
                $record->save();
                Log::info('Project settings updated.', ['opportunity_id' => $record->id, 'changes' => $changes, 'by_user_id' => $request->user()->id]);
            }

            return response()->json(['success' => true, 'data' => $this->project($record)]);
        });
    }

    public function reviewApplication(Request $request, int $project, int $application, ProjectApplications $service): JsonResponse
    {
        $opportunity = $this->ownedProject($request, $project);
        $validated = $request->validate([
            'status' => ['required', Rule::in([ProjectApplication::STATUS_REVIEWED, ProjectApplication::STATUS_REJECTED])],
        ]);

        try {
            $record = $service->review($opportunity, $application, $validated['status'], $request->user());
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'data' => ['id' => $record->id, 'status' => $record->status]]);
    }

    public function showTeam(Request $request, int $project): JsonResponse
    {
        $opportunity = $this->ownedProject($request, $project);

        return response()->json(['success' => true, 'data' => $this->team($opportunity->currentTeam())]);
    }

    public function storeTeam(Request $request, int $project, TeamFormation $formation): JsonResponse
    {
        $opportunity = $this->ownedProject($request, $project);
        $validated = $request->validate([
            'members' => ['present', 'array', 'max:40'],
            'members.*.application_id' => ['required', 'integer'],
            'members.*.role_id' => ['required', 'integer'],
        ]);

        try {
            $team = $formation->save($opportunity, $request->user(), array_map(fn (array $member): array => [
                'application_id' => (int) $member['application_id'],
                'role_id' => (int) $member['role_id'],
            ], $validated['members']));
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'message' => 'تم حفظ الفريق.', 'data' => $this->team($team)]);
    }

    public function confirmTeam(Request $request, int $team, TeamFormation $formation): JsonResponse
    {
        $record = $this->ownedTeam($request, $team);

        try {
            $record = $formation->confirm($record, $request->user());
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'message' => 'تم تأكيد الفريق وأصبح جاهزًا للتنفيذ.', 'data' => $this->team($record)]);
    }

    private function project(ProjectOpportunity $opportunity): array
    {
        $opportunity->loadMissing('activeVersion.roles');

        return [
            'id' => $opportunity->id,
            'project_request_id' => $opportunity->project_request_id,
            'title' => $opportunity->title,
            'summary' => $opportunity->summary,
            'status' => $opportunity->status,
            'project_status' => $opportunity->project_status,
            'accepting_applications' => $opportunity->acceptsApplications(),
            'allowed_transitions' => ProjectOpportunity::MANUAL_TRANSITIONS[$opportunity->project_status] ?? [],
            'min_weekly_hours' => $opportunity->min_weekly_hours,
            'estimated_duration' => $opportunity->activeVersion->proposal['estimated_duration'] ?? null,
            'version' => $opportunity->activeVersion->version,
            'published_at' => $opportunity->published_at,
            'team_size' => (int) $opportunity->activeVersion->roles->sum('headcount'),
        ];
    }

    private function team(?ProjectTeam $team): ?array
    {
        if (! $team) {
            return null;
        }
        $team->load(['members.studentProfile.user:id,name', 'members.role:id,title,category,headcount', 'leader.user:id,name']);

        return [
            'id' => $team->id,
            'status' => $team->status,
            'created_at' => $team->created_at,
            'status_changed_at' => $team->status_changed_at,
            'leader' => $team->leader ? [
                'student_profile_id' => $team->leader->id,
                'name' => $team->leader->user?->name,
                'assigned_at' => $team->leader_assigned_at,
            ] : null,
            'members' => $team->members->sortBy('id')->map(fn (ProjectTeamMember $member): array => [
                'id' => $member->id,
                'student_profile_id' => $member->student_profile_id,
                'application_id' => $member->project_application_id,
                'name' => $member->studentProfile?->user?->name,
                'university' => $member->studentProfile?->university,
                'academic_major' => $member->studentProfile?->academic_major,
                'role' => [
                    'id' => $member->role->id,
                    'title' => $member->role->title,
                    'category_label' => ProjectStaffing::ROLE_CATEGORIES[$member->role->category] ?? null,
                ],
                'is_leader' => $member->student_profile_id === $team->leader_student_profile_id,
                'joined_at' => $member->created_at,
            ])->values()->all(),
        ];
    }

    private function ownedProject(Request $request, int $project): ProjectOpportunity
    {
        return app(ProjectAccess::class)->asLeader($request->user(), $project);
    }

    private function ownedTeam(Request $request, int $team): ProjectTeam
    {
        $record = ProjectTeam::query()->find($team);
        abort_unless($record, 404, 'الفريق غير موجود.');
        // التحقق من القيادة عبر المشروع يعيد 404 نفسها لفريق مشروع لا يقوده المستخدم
        $this->ownedProject($request, $record->project_opportunity_id);

        return $record;
    }

    private function failure(ProjectActionException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
            'reason' => $exception->reason,
        ], $exception->status);
    }
}
