<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectDeliverable;
use App\Models\ProjectMemberEvaluation;
use App\Models\ProjectMilestone;
use App\Models\ProjectOpportunity;
use App\Models\ProjectTask;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\ProjectActionException;
use App\Services\Projects\ProjectExecution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * مساحة التنفيذ (لوحة المشروع FR-25): القائد يدير المهام والمراحل ويراجع التسليمات ويقيّم الأعضاء؛ العضو يرى المهام
 * ويسلّم ما أُسند إليه؛ المؤسسة تتابع التقدم وترى التسليمات المقبولة وتقيّم المشروع بعد إكماله؛ مدير المنصة يقرأ.
 * تقييمات الأعضاء لا تظهر للمؤسسة، وكل عضو يرى تقييمه وتسليماته فقط.
 */
class ProjectExecutionController extends Controller
{
    public function __construct(private readonly ProjectAccess $access, private readonly ProjectExecution $execution) {}

    public function show(Request $request, int $project): JsonResponse
    {
        [$record, $role, $membership] = $this->access->executionParticipant($request->user(), $project);

        return response()->json(['success' => true, 'data' => $this->dashboard($record, $role, $membership)]);
    }

    public function storeTask(Request $request, int $project): JsonResponse
    {
        $record = $this->access->asLeader($request->user(), $project);

        return $this->run(fn () => $this->execution->createTask($record, $request->user(), $request->all()),
            $record, ProjectAccess::LEADER, 'أُضيفت المهمة.', 201);
    }

    public function updateTask(Request $request, int $project, int $task): JsonResponse
    {
        $record = $this->access->asLeader($request->user(), $project);

        return $this->run(fn () => $this->execution->updateTask($record, $request->user(), $task, $request->all()),
            $record, ProjectAccess::LEADER, 'تم تحديث المهمة.');
    }

    public function updateMilestone(Request $request, int $project, int $milestone): JsonResponse
    {
        $record = $this->access->asLeader($request->user(), $project);
        $validated = $request->validate(['status' => ['required', Rule::in(ProjectMilestone::STATUSES)]]);

        return $this->run(fn () => $this->execution->updateMilestone($record, $request->user(), $milestone, $validated['status']),
            $record, ProjectAccess::LEADER, 'تم تحديث المرحلة.');
    }

    public function submitDeliverable(Request $request, int $project, int $task): JsonResponse
    {
        [$record, $membership] = $this->access->asMember($request->user(), $project);

        return $this->run(fn () => $this->execution->submitDeliverable($record, $membership, $request->user(), $task, $request->only(['reference_url', 'notes'])),
            $record, ProjectAccess::MEMBER, 'تم إرسال التسليم للمراجعة.', 201, $membership);
    }

    public function reviewDeliverable(Request $request, int $project, int $deliverable): JsonResponse
    {
        $record = $this->access->asLeader($request->user(), $project);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'request_revision'])],
            'feedback' => ['nullable', 'string', 'max:3000'],
        ]);

        return $this->run(fn () => $this->execution->reviewDeliverable($record, $request->user(), $deliverable, $validated['decision'], $validated['feedback'] ?? null),
            $record, ProjectAccess::LEADER, $validated['decision'] === 'accept' ? 'تم قبول التسليم.' : 'طُلب تعديل التسليم.');
    }

    public function complete(Request $request, int $project): JsonResponse
    {
        $record = $this->access->asLeader($request->user(), $project);

        return $this->run(fn () => $this->execution->complete($record, $request->user()),
            $record, ProjectAccess::LEADER, 'اكتمل المشروع وأُضيفت المشاركة إلى سجل أدلة الفريق.');
    }

    public function evaluate(Request $request, int $project): JsonResponse
    {
        $record = $this->access->asLeader($request->user(), $project);

        return $this->run(fn () => $this->execution->evaluateMember($record, $request->user(), $request->all()),
            $record, ProjectAccess::LEADER, 'تم تسجيل التقييم.', 201);
    }

    public function feedback(Request $request, int $project): JsonResponse
    {
        $record = $this->access->asOrganization($request->user(), $project);

        return $this->run(fn () => $this->execution->submitClientFeedback($record, $request->user(), $request->all()),
            $record, ProjectAccess::ORGANIZATION, 'شكرًا لتقييمك.', 201);
    }

    private function run(callable $action, ProjectOpportunity $project, string $role, string $message, int $status = 200, ?ProjectTeamMember $membership = null): JsonResponse
    {
        try {
            $action();
        } catch (ProjectActionException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage(), 'reason' => $exception->reason], $exception->status);
        }

        return response()->json(['success' => true, 'message' => $message, 'data' => $this->dashboard($project->refresh(), $role, $membership)], $status);
    }

    private function dashboard(ProjectOpportunity $project, string $role, ?ProjectTeamMember $membership): array
    {
        $this->execution->ensureMilestonesFor($project);

        $team = $project->teams()->where('status', '!=', ProjectTeam::STATUS_CANCELLED)->latest('id')
            ->with(['members.studentProfile.user:id,name', 'members.role:id,title'])->first();
        $milestones = $project->milestones()->get();
        $tasks = $project->tasks()->with(['deliverables.reviewedBy:id,name', 'assignee.studentProfile.user:id,name'])->orderBy('id')->get();
        $leader = $project->activeLeaderAssignment()->with('leader:id,name')->first()?->leader;
        $isLeader = $role === ProjectAccess::LEADER;
        $isStaff = $isLeader || $role === ProjectAccess::ADMIN;
        $inProgress = $project->project_status === ProjectOpportunity::PROJECT_IN_PROGRESS;
        $completed = $project->project_status === ProjectOpportunity::PROJECT_COMPLETED;

        $countable = $tasks->where('status', '!=', ProjectTask::STATUS_CANCELLED);
        $doneTasks = $countable->where('status', ProjectTask::STATUS_DONE)->count();
        $doneMilestones = $milestones->where('status', ProjectMilestone::STATUS_COMPLETED)->count();
        $evaluations = $team ? ProjectMemberEvaluation::query()->whereIn('project_team_member_id', $team->members->pluck('id'))->get()->keyBy('project_team_member_id') : collect();
        $feedback = $project->clientFeedback()->first();

        return [
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'summary' => $project->summary,
                'organization_name' => $project->organization?->organization_name,
                'project_status' => $project->project_status,
                'execution_started_at' => $project->execution_started_at,
                'completed_at' => $project->completed_at,
                'leader' => $leader ? ['name' => $leader->name] : null,
                'timeline' => $project->agreedPlan?->content['timeline'] ?? null,
                'agreed_plan_version' => $project->agreedPlan?->version,
            ],
            'viewer_role' => $role,
            'my_member_id' => $membership?->id,
            'progress' => [
                'tasks_total' => $countable->count(),
                'tasks_done' => $doneTasks,
                'tasks_in_review' => $countable->where('status', ProjectTask::STATUS_IN_REVIEW)->count(),
                'milestones_total' => $milestones->count(),
                'milestones_completed' => $doneMilestones,
                'percent' => $milestones->count() + $countable->count() === 0 ? 0
                    : (int) round(100 * ($doneTasks + $doneMilestones) / ($countable->count() + $milestones->count())),
            ],
            'permissions' => [
                'manage' => $isLeader && $inProgress,
                'complete' => $isLeader && $inProgress,
                'evaluate' => $isLeader && $completed,
                'submit' => $role === ProjectAccess::MEMBER && $inProgress,
                'feedback' => $role === ProjectAccess::ORGANIZATION && $completed && ! $feedback,
            ],
            'team' => $team ? [
                'status' => $team->status,
                'members' => $team->members->sortBy('id')->map(fn (ProjectTeamMember $member): array => [
                    'id' => $member->id,
                    'name' => $member->studentProfile?->user?->name,
                    'role' => $member->role?->title,
                    // التقييم يظهر للقائد ومدير المنصة، وللعضو نفسه فقط
                    'evaluation' => ($isStaff || $membership?->id === $member->id) && $evaluations->has($member->id)
                        ? $this->evaluationPayload($evaluations->get($member->id)) : null,
                    'evaluated' => $isStaff ? $evaluations->has($member->id) : null,
                ])->values(),
            ] : null,
            'milestones' => $milestones->map(fn (ProjectMilestone $milestone): array => [
                'id' => $milestone->id,
                'position' => $milestone->position,
                'title' => $milestone->title,
                'description' => $milestone->description,
                'due_date' => $milestone->due_date?->format('Y-m-d'),
                'status' => $milestone->status,
                'completed_at' => $milestone->completed_at,
            ])->values(),
            'tasks' => $tasks->map(fn (ProjectTask $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'priority' => $task->priority,
                'status' => $task->status,
                'due_date' => $task->due_date?->format('Y-m-d'),
                'milestone_id' => $task->project_milestone_id,
                'assignee_member_id' => $task->assignee_member_id,
                'assignee_name' => $task->assignee?->studentProfile?->user?->name,
                'completed_at' => $task->completed_at,
                'deliverables' => $this->visibleDeliverables($task, $role, $membership),
            ])->values(),
            'client_feedback' => $feedback && in_array($role, [ProjectAccess::ORGANIZATION, ProjectAccess::LEADER, ProjectAccess::ADMIN], true) ? [
                'satisfaction_score' => $feedback->satisfaction_score,
                'leader_score' => $feedback->leader_score,
                'would_recommend' => $feedback->would_recommend,
                'comments' => $feedback->comments,
                'created_at' => $feedback->created_at,
            ] : null,
            'evaluation_criteria' => ProjectMemberEvaluation::CRITERIA,
        ];
    }

    /** القائد والمدير: كل التسليمات. العضو: تسليماته على مهامه. المؤسسة: التسليمات المقبولة فقط (دليل التسليم). */
    private function visibleDeliverables(ProjectTask $task, string $role, ?ProjectTeamMember $membership): array
    {
        $deliverables = $task->deliverables->sortByDesc('revision');
        $deliverables = match ($role) {
            ProjectAccess::LEADER, ProjectAccess::ADMIN => $deliverables,
            ProjectAccess::MEMBER => $deliverables->where('project_team_member_id', $membership?->id),
            default => $deliverables->where('status', ProjectDeliverable::STATUS_ACCEPTED),
        };

        return $deliverables->map(fn (ProjectDeliverable $deliverable): array => [
            'id' => $deliverable->id,
            'revision' => $deliverable->revision,
            'reference_url' => $deliverable->reference_url,
            'notes' => $deliverable->notes,
            'status' => $deliverable->status,
            'feedback' => $role === ProjectAccess::ORGANIZATION ? null : $deliverable->feedback,
            'submitted_at' => $deliverable->created_at,
            'reviewed_at' => $deliverable->reviewed_at,
            'reviewed_by' => $deliverable->reviewedBy?->name,
        ])->values()->all();
    }

    private function evaluationPayload(ProjectMemberEvaluation $evaluation): array
    {
        return [
            'overall_score' => $evaluation->overall_score,
            'criteria' => $evaluation->criteria,
            'comments' => $evaluation->comments,
            'created_at' => $evaluation->created_at,
        ];
    }
}
