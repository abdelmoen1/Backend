<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectTask;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\StudentEvidence;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** مشاريع الطالب التي هو عضو في فريقها، وسجل أدلة خبرته (FR-09). كل طالب يرى سجله فقط. */
class StudentProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $memberships = ProjectTeamMember::query()
            ->where('student_profile_id', $profile->id)
            ->whereHas('team', fn ($query) => $query->where('status', '!=', ProjectTeam::STATUS_CANCELLED))
            ->with(['team.opportunity.organization:id,organization_name', 'role:id,title'])
            ->latest('id')
            ->get();

        return response()->json(['success' => true, 'data' => $memberships->map(function (ProjectTeamMember $member): array {
            $project = $member->team->opportunity;
            $myTasks = ProjectTask::query()->where('assignee_member_id', $member->id);

            return [
                'id' => $project->id,
                'title' => $project->title,
                'organization_name' => $project->organization?->organization_name,
                'project_status' => $project->project_status,
                'team_status' => $member->team->status,
                'role' => $member->role?->title,
                'open_tasks' => (clone $myTasks)->whereIn('status', ProjectTask::OPEN_STATUSES)->count(),
                'done_tasks' => (clone $myTasks)->where('status', ProjectTask::STATUS_DONE)->count(),
            ];
        })->values()]);
    }

    public function evidence(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $records = $profile->evidence()
            ->with(['opportunity.organization:id,organization_name', 'evaluation'])
            ->latest('issued_at')
            ->get();

        return response()->json(['success' => true, 'data' => $records->map(fn (StudentEvidence $evidence): array => [
            'id' => $evidence->id,
            'project' => [
                'id' => $evidence->project_opportunity_id,
                'title' => $evidence->opportunity?->title,
                'organization_name' => $evidence->opportunity?->organization?->organization_name,
            ],
            'participation' => $evidence->participation,
            'role_title' => $evidence->role_title,
            'summary' => $evidence->summary,
            'skills_used' => $evidence->skills_used,
            'completed_tasks' => $evidence->completed_tasks,
            'completed_tasks_count' => $evidence->completed_tasks_count,
            'leader_evaluation' => $evidence->evaluation ? [
                'overall_score' => $evidence->evaluation->overall_score,
                'criteria' => $evidence->evaluation->criteria,
                'comments' => $evidence->evaluation->comments,
            ] : null,
            'client_satisfaction_score' => $evidence->client_satisfaction_score,
            'final_result' => $evidence->final_result,
            'issued_at' => $evidence->issued_at,
        ])->values()]);
    }

    private function profile(User $user)
    {
        abort_unless($user->role === User::ROLE_STUDENT && $user->studentProfile, 403, 'هذه الصفحة للطلاب فقط.');

        return $user->studentProfile;
    }
}
