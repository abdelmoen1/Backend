<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectApplication;
use App\Models\ProjectLeaderAssignment;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\ProjectActionException;
use App\Services\Projects\ProjectPlanning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** المشاريع التي يقودها الطالب حاليًا (تعيينات سارية من مدير المنصة) وبدء التنفيذ بعد الاتفاق على الخطة. */
class LeaderProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $assignments = ProjectLeaderAssignment::query()
            ->active()
            ->where('leader_user_id', $request->user()->id)
            ->with('opportunity.organization:id,organization_name')
            ->latest('assigned_at')
            ->get();

        return response()->json(['success' => true, 'data' => $assignments->map(function (ProjectLeaderAssignment $assignment): array {
            $project = $assignment->opportunity;
            $team = $project->currentTeam();

            return [
                'id' => $project->id,
                'title' => $project->title,
                'summary' => $project->summary,
                'organization_name' => $project->organization?->organization_name,
                'status' => $project->status,
                'project_status' => $project->project_status,
                'assigned_at' => $assignment->assigned_at,
                'applications_count' => $project->applications()->whereIn('status', ProjectApplication::ACTIVE_STATUSES)->count(),
                'team_status' => $team?->status,
                'plan_agreed' => $project->agreed_plan_version_id !== null,
            ];
        })->values()]);
    }

    public function start(Request $request, int $project, ProjectAccess $access, ProjectPlanning $planning): JsonResponse
    {
        $record = $access->asLeader($request->user(), $project);

        try {
            $record = $planning->startExecution($record, $request->user());
        } catch (ProjectActionException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage(), 'reason' => $exception->reason], $exception->status);
        }

        return response()->json(['success' => true, 'message' => 'بدأ تنفيذ المشروع وفق الخطة المتفق عليها.', 'data' => [
            'id' => $record->id,
            'project_status' => $record->project_status,
            'execution_started_at' => $record->execution_started_at,
        ]]);
    }
}
