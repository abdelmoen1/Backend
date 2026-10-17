<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ProjectApplication;
use App\Models\ProjectLeaderAssignment;
use App\Models\ProjectOpportunity;
use App\Models\ProjectRole;
use App\Models\ProjectTeam;
use App\Models\User;
use App\Services\Projects\LeaderAssignments;
use App\Services\Projects\ProjectActionException;
use App\Services\Projects\ProjectReview;
use App\Services\Projects\ProjectStaffing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * لوحة مدير المنصة (FR-34/35/36): مراجعة طلبات المؤسسات، تعيين قادة الفرق، إدارة الحسابات، وإحصاءات التشغيل.
 * كل المسارات خلف middleware admin؛ لا يُعتمد على إخفاء الواجهة.
 */
class AdminController extends Controller
{
    public function overview(): JsonResponse
    {
        $byRole = User::query()->select('role', DB::raw('count(*) as total'))->groupBy('role')->pluck('total', 'role');
        $byStatus = ProjectOpportunity::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $byStage = ProjectOpportunity::query()->where('status', ProjectOpportunity::STATUS_PUBLISHED)
            ->select('project_status', DB::raw('count(*) as total'))->groupBy('project_status')->pluck('total', 'project_status');

        return response()->json(['success' => true, 'data' => [
            'users' => [
                'total' => (int) $byRole->sum(),
                'students' => (int) ($byRole['student'] ?? 0),
                'organizations' => (int) ($byRole['organization'] ?? 0),
                'admins' => (int) ($byRole['admin'] ?? 0),
                'deactivated' => User::query()->where('is_active', false)->count(),
            ],
            'projects' => [
                'by_status' => $byStatus,
                'by_stage' => $byStage,
                'pending_review' => (int) ($byStatus[ProjectOpportunity::STATUS_PENDING_REVIEW] ?? 0),
                'without_leader' => ProjectOpportunity::query()->where('status', ProjectOpportunity::STATUS_PUBLISHED)
                    ->whereDoesntHave('leaderAssignments', fn ($query) => $query->active())->count(),
            ],
            'applications' => ProjectApplication::query()->count(),
            'teams' => ProjectTeam::query()->where('status', '!=', ProjectTeam::STATUS_CANCELLED)->count(),
            'recent_activity' => AuditLog::query()->with('user:id,name,role')->latest('id')->limit(10)->get()
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'entity_type' => $log->entity_type,
                    'entity_id' => $log->entity_id,
                    'actor' => $log->user?->name,
                    'created_at' => $log->created_at,
                ]),
        ]]);
    }

    public function projects(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(ProjectOpportunity::STATUSES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $projects = ProjectOpportunity::query()
            ->with(['organization:id,user_id,organization_name', 'activeVersion:id,version,approved_at', 'activeLeaderAssignment.leader:id,name'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where('title', 'like', '%'.$search.'%'))
            // قائمة المراجعة أولًا، ثم الأحدث
            ->orderByRaw('case when status = ? then 0 else 1 end', [ProjectOpportunity::STATUS_PENDING_REVIEW])
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => collect($projects->items())->map(fn (ProjectOpportunity $project): array => $this->summary($project))->all(),
            'meta' => ['current_page' => $projects->currentPage(), 'last_page' => $projects->lastPage(), 'total' => $projects->total()],
        ]);
    }

    public function project(int $project): JsonResponse
    {
        $record = ProjectOpportunity::query()
            ->with(['organization.user:id,name,email', 'activeVersion.roles.skills.skill:id,name', 'projectRequest:id,title,status,revision_note'])
            ->findOrFail($project);
        $team = $record->currentTeam();
        $team?->loadCount('members');

        return response()->json(['success' => true, 'data' => $this->summary($record) + [
            'proposal' => $record->activeVersion?->proposal,
            'roles' => $record->activeVersion?->roles->map(fn (ProjectRole $role): array => [
                'id' => $role->id,
                'title' => $role->title,
                'category_label' => ProjectStaffing::ROLE_CATEGORIES[$role->category] ?? $role->category,
                'headcount' => $role->headcount,
                'skills' => $role->skills->map(fn ($skill): array => [
                    'name' => $skill->skill?->name,
                    'importance' => $skill->importance,
                    'min_level' => $skill->min_level,
                ])->values(),
            ])->values(),
            'organization_contact' => [
                'name' => $record->organization?->user?->name,
                'email' => $record->organization?->user?->email,
            ],
            'applications_count' => $record->applications()->count(),
            'team' => $team ? ['id' => $team->id, 'status' => $team->status, 'members_count' => $team->members_count] : null,
            'plan' => [
                'versions' => $record->planVersions()->count(),
                'agreed_version_id' => $record->agreed_plan_version_id,
                'agreed_at' => $record->agreed_at,
            ],
            'leader_history' => $record->leaderAssignments()->with(['leader:id,name,email', 'assignedBy:id,name', 'revokedBy:id,name'])->latest('id')->get()
                ->map(fn (ProjectLeaderAssignment $assignment): array => [
                    'id' => $assignment->id,
                    'leader' => ['id' => $assignment->leader_user_id, 'name' => $assignment->leader?->name, 'email' => $assignment->leader?->email],
                    'active' => $assignment->isActive(),
                    'assigned_at' => $assignment->assigned_at,
                    'assigned_by' => $assignment->assignedBy?->name,
                    'override_justification' => $assignment->override_justification,
                    'revoked_at' => $assignment->revoked_at,
                    'revoked_by' => $assignment->revokedBy?->name,
                    'revocation_reason' => $assignment->revocation_reason,
                ]),
            'audit' => AuditLog::query()->where('entity_type', 'ProjectOpportunity')->where('entity_id', $record->id)
                ->with('user:id,name')->latest('id')->limit(30)->get()
                ->map(fn (AuditLog $log): array => ['action' => $log->action, 'actor' => $log->user?->name, 'metadata' => $log->metadata, 'created_at' => $log->created_at]),
        ]]);
    }

    public function review(Request $request, int $project, ProjectReview $review): JsonResponse
    {
        $record = ProjectOpportunity::query()->findOrFail($project);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(ProjectReview::DECISIONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $record = $review->decide($record, $request->user(), $validated['decision'], $validated['note'] ?? null);
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'data' => $this->summary($record->load(['organization', 'activeVersion', 'activeLeaderAssignment.leader']))]);
    }

    public function leaderCandidates(Request $request, int $project, LeaderAssignments $leaders): JsonResponse
    {
        $record = ProjectOpportunity::query()->findOrFail($project);
        $validated = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100']]);

        $candidates = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('is_active', true)
            ->with('studentProfile')
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json(['success' => true, 'data' => $candidates->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'university' => $user->studentProfile?->university,
            'specialization' => $user->studentProfile?->professional_specialization,
            'weekly_availability_hours' => $user->studentProfile?->weekly_availability_hours,
            'eligibility' => $leaders->eligibility($user, $record),
        ])->values()]);
    }

    public function assignLeader(Request $request, int $project, LeaderAssignments $leaders): JsonResponse
    {
        $record = ProjectOpportunity::query()->findOrFail($project);
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'justification' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $assignment = $leaders->assign($record, $request->user(), (int) $validated['user_id'], $validated['justification'] ?? null);
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'message' => 'تم تعيين قائد الفريق.', 'data' => [
            'id' => $assignment->id,
            'leader_user_id' => $assignment->leader_user_id,
            'override' => $assignment->override_justification !== null,
        ]]);
    }

    public function revokeLeader(Request $request, int $project, LeaderAssignments $leaders): JsonResponse
    {
        $record = ProjectOpportunity::query()->findOrFail($project);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $leaders->revoke($record, $request->user(), $validated['reason']);
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['success' => true, 'message' => 'تم إلغاء تعيين قائد الفريق.']);
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', 'nullable', Rule::in([User::ROLE_STUDENT, User::ROLE_ORGANIZATION, User::ROLE_ADMIN])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $users = User::query()
            ->when($validated['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => collect($users->items())->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->isActive(),
                'deactivated_at' => $user->deactivated_at,
                'created_at' => $user->created_at,
            ])->all(),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ]);
    }

    public function updateUser(Request $request, int $user): JsonResponse
    {
        $record = User::query()->findOrFail($user);
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        if ($record->id === $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'لا يمكنك إيقاف حسابك.'], 422);
        }

        DB::transaction(function () use ($record, $validated, $request): void {
            $record->forceFill([
                'is_active' => $validated['is_active'],
                'deactivated_at' => $validated['is_active'] ? null : now(),
            ])->save();
            if (! $validated['is_active']) {
                // الإيقاف يُنهي كل الجلسات القائمة فورًا
                $record->tokens()->delete();
            }
            AuditLog::record($request->user(), $validated['is_active'] ? 'user.activated' : 'user.deactivated', $record);
        });

        return response()->json(['success' => true, 'data' => ['id' => $record->id, 'is_active' => $record->isActive()]]);
    }

    private function summary(ProjectOpportunity $project): array
    {
        $leader = $project->activeLeaderAssignment?->leader;

        return [
            'id' => $project->id,
            'project_request_id' => $project->project_request_id,
            'title' => $project->title,
            'summary' => $project->summary,
            'status' => $project->status,
            'project_status' => $project->project_status,
            'organization' => ['id' => $project->organization_id, 'name' => $project->organization?->organization_name],
            'version' => $project->activeVersion?->version,
            'submitted_for_review_at' => $project->submitted_for_review_at,
            'reviewed_at' => $project->reviewed_at,
            'review_note' => $project->moderation_note,
            'published_at' => $project->published_at,
            'leader' => $leader ? ['id' => $leader->id, 'name' => $leader->name] : null,
            'agreed_at' => $project->agreed_at,
        ];
    }

    private function failure(ProjectActionException $exception): JsonResponse
    {
        $body = ['success' => false, 'message' => $exception->getMessage(), 'reason' => $exception->reason];
        if (isset($exception->context['eligibility'])) {
            $body['eligibility'] = $exception->context['eligibility'];
        }

        return response()->json($body, $exception->status);
    }
}
