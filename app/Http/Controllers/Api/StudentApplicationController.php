<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectApplication;
use App\Services\Projects\OpportunityMatcher;
use App\Services\Projects\ProjectActionException;
use App\Services\Projects\ProjectApplications;
use App\Services\Projects\ProjectStaffing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * طلبات انضمام الطالب. الطالب يقدّم ويسحب لنفسه فقط: الملف من الجلسة، وأي student_profile_id في الطلب يُتجاهل.
 * الأهلية وحالة الفرصة تُتحقق في الخادم عند كل تقديم (ProjectApplications).
 */
class StudentApplicationController extends Controller
{
    public function index(Request $request, OpportunityMatcher $matcher): JsonResponse
    {
        if ($response = $this->studentsOnly($request, $matcher)) {
            return $response;
        }

        $applications = $request->user()->studentProfile->applications()
            ->with(['opportunity.organization:id,organization_name', 'preferredRole:id,title,category', 'opportunity.teams.members'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $applications->map(fn (ProjectApplication $application): array => $this->present($application))->values()->all(),
        ]);
    }

    public function store(Request $request, int $opportunity, OpportunityMatcher $matcher, ProjectApplications $service): JsonResponse
    {
        if ($response = $this->studentsOnly($request, $matcher)) {
            return $response;
        }

        $validated = $request->validate([
            'preferred_role_id' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        try {
            $application = $service->apply(
                $request->user()->studentProfile,
                $opportunity,
                isset($validated['preferred_role_id']) ? (int) $validated['preferred_role_id'] : null,
                isset($validated['note']) ? (trim($validated['note']) ?: null) : null,
            );
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        $application->load(['opportunity.organization:id,organization_name', 'preferredRole:id,title,category']);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلبك. ستراجع المؤسسة المتقدمين، وستظهر حالة طلبك هنا.',
            'data' => $this->present($application),
        ], 201);
    }

    public function withdraw(Request $request, int $application, OpportunityMatcher $matcher, ProjectApplications $service): JsonResponse
    {
        if ($response = $this->studentsOnly($request, $matcher)) {
            return $response;
        }

        try {
            $record = $service->withdraw($request->user()->studentProfile, $application);
        } catch (ProjectActionException $exception) {
            return $this->failure($exception);
        }

        $record->load(['opportunity.organization:id,organization_name', 'preferredRole:id,title,category']);

        return response()->json([
            'success' => true,
            'message' => 'تم سحب طلبك.',
            'data' => $this->present($record),
        ]);
    }

    private function present(ProjectApplication $application): array
    {
        $opportunity = $application->opportunity;
        $team = $opportunity?->relationLoaded('teams')
            ? $opportunity->teams->firstWhere(fn ($team): bool => $team->members->contains('student_profile_id', $application->student_profile_id))
            : null;

        return [
            'id' => $application->id,
            'status' => $application->status,
            'note' => $application->note,
            'applied_at' => $application->created_at,
            'status_changed_at' => $application->status_changed_at,
            'preferred_role' => $application->preferredRole ? [
                'id' => $application->preferredRole->id,
                'title' => $application->preferredRole->title,
                'category_label' => ProjectStaffing::ROLE_CATEGORIES[$application->preferredRole->category] ?? null,
            ] : null,
            'opportunity' => $opportunity ? [
                'id' => $opportunity->id,
                'title' => $opportunity->title,
                'organization' => $opportunity->organization?->organization_name,
                'project_status' => $opportunity->project_status,
                // التفاصيل تُفتح فقط إن كانت الفرصة ما زالت منشورة
                'available' => $opportunity->status === 'published',
            ] : null,
            'team' => $team ? [
                'status' => $team->status,
                'is_leader' => $team->leader_student_profile_id === $application->student_profile_id,
            ] : null,
        ];
    }

    private function failure(ProjectActionException $exception): JsonResponse
    {
        $body = ['success' => false, 'message' => $exception->getMessage(), 'reason' => $exception->reason];
        if (isset($exception->context['application'])) {
            $body['application'] = ['id' => $exception->context['application']->id, 'status' => $exception->context['application']->status];
        }

        return response()->json($body, $exception->status);
    }

    private function studentsOnly(Request $request, OpportunityMatcher $matcher): ?JsonResponse
    {
        if ($request->user()->role !== 'student' || ! $request->user()->studentProfile) {
            return response()->json(['success' => false, 'message' => 'هذه العملية متاحة للطلاب فقط.'], 403);
        }
        if (! $matcher->canBrowse($request->user())) {
            return response()->json(['success' => false, 'message' => 'أكمل ملفك الشخصي أولًا.'], 403);
        }

        return null;
    }
}
