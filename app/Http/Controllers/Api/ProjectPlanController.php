<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectOpportunity;
use App\Models\ProjectPlanComment;
use App\Models\ProjectPlanVersion;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\ProjectActionException;
use App\Services\Projects\ProjectPlanning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * مساحة التخطيط والاتفاق المشتركة: القائد يكتب ويقدّم، المؤسسة تراجع وتوافق أو تطلب تعديلًا، والطرفان يعلّقان.
 * مدير المنصة يقرأ فقط. الصلاحية تُحسم في الخادم لكل إجراء حسب صفة المستخدم في هذا المشروع.
 */
class ProjectPlanController extends Controller
{
    public function __construct(private readonly ProjectAccess $access, private readonly ProjectPlanning $planning) {}

    public function show(Request $request, int $project): JsonResponse
    {
        [$record, $role] = $this->access->participant($request->user(), $project);

        return response()->json(['success' => true, 'data' => $this->workspace($record, $role)]);
    }

    public function saveDraft(Request $request, int $project): JsonResponse
    {
        $record = $this->access->asLeader($request->user(), $project);

        try {
            $this->planning->saveDraft($record, $request->user(), $request->input('content', []) ?: []);
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'message' => 'تم حفظ المسودة.', 'data' => $this->workspace($record->refresh(), ProjectAccess::LEADER)]);
    }

    public function submit(Request $request, int $project, int $version): JsonResponse
    {
        $record = $this->access->asLeader($request->user(), $project);

        try {
            $this->planning->submit($record, $request->user(), $version);
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'message' => 'تم تقديم النسخة للمؤسسة مع موافقتك عليها.', 'data' => $this->workspace($record->refresh(), ProjectAccess::LEADER)]);
    }

    public function decide(Request $request, int $project, int $version): JsonResponse
    {
        $record = $this->access->asOrganization($request->user(), $project);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'request_changes'])],
            'content_hash' => ['required', 'string', 'size:64'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->planning->decide($record, $request->user(), $version, $validated['decision'], $validated['content_hash'], $validated['note'] ?? null);
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'data' => $this->workspace($record->refresh(), ProjectAccess::ORGANIZATION)]);
    }

    public function comment(Request $request, int $project, int $version): JsonResponse
    {
        [$record, $role] = $this->access->participant($request->user(), $project);
        abort_if($role === ProjectAccess::ADMIN, 403, 'التعليق متاح لقائد الفريق والمؤسسة فقط.');
        $validated = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);

        try {
            $this->planning->comment($record, $request->user(), $role, $version, $validated['body']);
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'data' => $this->workspace($record, $role)], 201);
    }

    private function workspace(ProjectOpportunity $project, string $role): array
    {
        $versions = $project->planVersions()
            ->with(['comments.user:id,name', 'submittedBy:id,name', 'decidedBy:id,name'])
            ->orderByDesc('version')
            ->get();
        $leader = $project->activeLeaderAssignment()->with('leader:id,name')->first()?->leader;
        $team = $project->currentTeam();
        $latest = $versions->first();

        return [
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'status' => $project->status,
                'project_status' => $project->project_status,
                'organization_name' => $project->organization?->organization_name,
                'leader' => $leader ? ['id' => $leader->id, 'name' => $leader->name] : null,
                'team_status' => $team?->status,
                'agreed_plan_version_id' => $project->agreed_plan_version_id,
                'agreed_at' => $project->agreed_at,
                'execution_started_at' => $project->execution_started_at,
                // ملخص المقترح المعتمد للاسترشاد عند كتابة الخطة
                'proposal' => [
                    'estimated_duration' => $project->activeVersion?->proposal['estimated_duration'] ?? null,
                    'estimated_cost' => $project->activeVersion?->proposal['estimated_cost'] ?? null,
                    'milestones' => $project->activeVersion?->proposal['milestones'] ?? null,
                ],
            ],
            'viewer_role' => $role,
            'permissions' => [
                'edit' => $role === ProjectAccess::LEADER && $project->agreed_plan_version_id === null
                    && $latest?->status !== ProjectPlanVersion::STATUS_SUBMITTED,
                'submit' => $role === ProjectAccess::LEADER && $latest?->status === ProjectPlanVersion::STATUS_DRAFT,
                'decide' => $role === ProjectAccess::ORGANIZATION && $latest?->status === ProjectPlanVersion::STATUS_SUBMITTED,
                'comment' => in_array($role, [ProjectAccess::LEADER, ProjectAccess::ORGANIZATION], true),
                'start_execution' => $role === ProjectAccess::LEADER && $project->agreed_plan_version_id !== null
                    && $team?->status === 'ready' && $project->project_status !== ProjectOpportunity::PROJECT_IN_PROGRESS,
            ],
            'financial_settings' => [
                'platform_commission_rate' => (float) config('gotrainee.platform_commission_rate'),
                'requirements_gathering_fee' => (float) config('gotrainee.requirements_gathering_fee'),
                'currencies' => config('gotrainee.currencies'),
                'default_currency' => config('gotrainee.default_currency'),
            ],
            'versions' => $versions->map(fn (ProjectPlanVersion $version): array => [
                'id' => $version->id,
                'version' => $version->version,
                'status' => $version->status,
                'content' => $version->content,
                'content_hash' => $version->content_hash,
                'is_agreed' => $version->id === $project->agreed_plan_version_id,
                'submitted_at' => $version->submitted_at,
                'submitted_by' => $version->submittedBy?->name,
                'organization_decision' => $version->organization_decision,
                'organization_decided_at' => $version->organization_decided_at,
                'organization_decided_by' => $version->decidedBy?->name,
                'organization_note' => $version->organization_note,
                'updated_at' => $version->updated_at,
                'comments' => $version->comments->sortBy('id')->map(fn (ProjectPlanComment $comment): array => [
                    'id' => $comment->id,
                    'author' => $comment->user?->name,
                    'author_role' => $comment->author_role,
                    'body' => $comment->body,
                    'created_at' => $comment->created_at,
                ])->values(),
            ])->values(),
        ];
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
