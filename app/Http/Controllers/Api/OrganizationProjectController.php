<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectApplication;
use App\Models\ProjectTeamMember;
use App\Services\Projects\ProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * عرض المشروع للمؤسسة المالكة للقراءة فقط: المرحلة، القائد المعيّن، تشكيلة الفريق (الاسم والدور فقط)، وحالة الخطة.
 * لا ترتيب مرشحين، لا نتائج تقييم، ولا بيانات تواصل الطلاب (NFR-10).
 */
class OrganizationProjectController extends Controller
{
    public function show(Request $request, int $project, ProjectAccess $access): JsonResponse
    {
        $record = $access->asOrganization($request->user(), $project);
        $leader = $record->activeLeaderAssignment()->with('leader:id,name')->first()?->leader;
        $team = $record->currentTeam();
        $team?->load(['members.studentProfile.user:id,name', 'members.role:id,title']);
        $latestPlan = $record->planVersions()->orderByDesc('version')->first();

        return response()->json(['success' => true, 'data' => [
            'id' => $record->id,
            'project_request_id' => $record->project_request_id,
            'title' => $record->title,
            'summary' => $record->summary,
            'status' => $record->status,
            'project_status' => $record->project_status,
            'review_note' => $record->moderation_note,
            'published_at' => $record->published_at,
            'leader' => $leader ? ['name' => $leader->name] : null,
            'applications_count' => $record->applications()->whereIn('status', ProjectApplication::ACTIVE_STATUSES)->count(),
            'team' => $team ? [
                'status' => $team->status,
                'members' => $team->members->sortBy('id')->map(fn (ProjectTeamMember $member): array => [
                    'name' => $member->studentProfile?->user?->name,
                    'role' => $member->role?->title,
                ])->values(),
            ] : null,
            'plan' => [
                'latest_version' => $latestPlan?->version,
                'latest_status' => $latestPlan?->status,
                'agreed' => $record->agreed_plan_version_id !== null,
                'agreed_at' => $record->agreed_at,
            ],
            'execution_started_at' => $record->execution_started_at,
        ]]);
    }
}
