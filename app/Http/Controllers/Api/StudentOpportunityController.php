<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectOpportunity;
use App\Models\ProjectRole;
use App\Models\ProjectRoleSkill;
use App\Services\Projects\OpportunityMatcher;
use App\Services\Projects\ProjectStaffing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * فرص المشاريع للطلاب. كل فرصة منشورة تظهر لكل طالب أكمل ملفه، مع حالة أهلية يحسبها الخادم لكل دور
 * (OpportunityMatcher) وأسبابها؛ عدم الأهلية لا يخفي الفرصة. الفرصة غير المنشورة (معلقة أو مسحوبة أو غير
 * موجودة) تُعامل كغير موجودة (404) حتى عند طلبها مباشرة بالمعرّف.
 * لا تُعاد بيانات المؤسسة الخاصة مثل التكلفة والميزانية والدفعات ولا محادثة الاكتشاف ولا مبررات الأدوار الداخلية.
 * لا يوجد مسار تقديم بعد؛ أي مسار يُضاف لاحقًا يجب أن يعيد التحقق من الأهلية في الخادم.
 */
class StudentOpportunityController extends Controller
{
    public function index(Request $request, OpportunityMatcher $matcher): JsonResponse
    {
        if ($response = $this->studentsOnly($request, $matcher)) {
            return $response;
        }

        $student = $request->user()->studentProfile;

        return response()->json([
            'success' => true,
            'data' => [
                'opportunities' => array_map(
                    fn (array $entry): array => $this->summary($entry),
                    $matcher->forStudent($student)
                ),
                'verified_skills' => $matcher->verifiedSkills($student)->values()->all(),
                'verified_levels' => $matcher->verifiedLevels($student)->values()->all(),
                'specialization' => $matcher->specialization($student),
            ],
        ]);
    }

    public function show(Request $request, int $opportunity, OpportunityMatcher $matcher): JsonResponse
    {
        if ($response = $this->studentsOnly($request, $matcher)) {
            return $response;
        }

        $entry = $matcher->findForStudent($request->user()->studentProfile, $opportunity);
        if ($entry === null) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الفرصة غير متاحة حاليًا.',
            ], 404);
        }

        $proposal = $entry['opportunity']->activeVersion->proposal;

        return response()->json([
            'success' => true,
            'data' => $this->summary($entry) + [
                'scope' => [
                    'proposed_solution' => $proposal['proposed_solution'] ?? null,
                    'objectives' => $proposal['objectives'] ?? [],
                    'target_users' => array_map(
                        fn (array $user): array => array_intersect_key($user, array_flip(['user', 'role', 'interaction'])),
                        array_filter($proposal['target_users'] ?? [], 'is_array')
                    ),
                    'functional_scope' => $proposal['functional_scope'] ?? [],
                    'out_of_scope' => $proposal['out_of_scope'] ?? [],
                    'milestones' => array_map(
                        fn (array $milestone): array => array_intersect_key($milestone, array_flip(['title', 'description', 'duration'])),
                        array_filter($proposal['milestones'] ?? [], 'is_array')
                    ),
                    'success_criteria' => $proposal['success_criteria'] ?? [],
                ],
            ],
        ]);
    }

    private function summary(array $entry): array
    {
        /** @var ProjectOpportunity $opportunity */
        $opportunity = $entry['opportunity'];

        return [
            'id' => $opportunity->id,
            'title' => $opportunity->title,
            'summary' => $opportunity->summary,
            'published_at' => $opportunity->published_at,
            'version' => $opportunity->activeVersion->version,
            'estimated_duration' => $opportunity->activeVersion->proposal['estimated_duration'] ?? null,
            'organization' => [
                'name' => $opportunity->organization?->organization_name,
                'industry' => $opportunity->organization?->industry,
            ],
            // أهلية الطالب يحسبها الخادم؛ لا تعني تقديمًا أو اختيارًا للفريق
            'eligibility' => $entry['eligibility'],
            'roles' => $opportunity->activeVersion->roles->map(function (ProjectRole $role) use ($entry): array {
                $evaluation = $entry['roles'][$role->id];

                return [
                    'id' => $role->id,
                    'category' => $role->category,
                    'category_label' => ProjectStaffing::ROLE_CATEGORIES[$role->category] ?? $role->category,
                    'title' => $role->title,
                    'responsibilities' => $role->responsibilities,
                    'headcount' => $role->headcount,
                    'mandatory_skills' => $this->skillNames($role, ProjectRoleSkill::MANDATORY),
                    'desirable_skills' => $this->skillNames($role, ProjectRoleSkill::DESIRABLE),
                    // المهارات الإلزامية مع المستوى الأدنى المطلوب ومستوى الطالب المثبت
                    'requirements' => $evaluation['skills'],
                    'eligibility' => [
                        'status' => $evaluation['status'],
                        'message' => $evaluation['message'],
                        'reasons' => $evaluation['reasons'],
                        'action' => $evaluation['action'],
                    ],
                    'matched' => $evaluation['status'] === OpportunityMatcher::ELIGIBLE,
                    'specialization_compatible' => ! in_array($evaluation['status'], [OpportunityMatcher::SPECIALIZATION_MISMATCH, OpportunityMatcher::PROFILE_INCOMPLETE], true),
                    'missing_skills' => array_values(array_map(
                        fn (array $skill): string => $skill['name'],
                        array_filter($evaluation['skills'], fn (array $skill): bool => ! $skill['met'])
                    )),
                ];
            })->all(),
            'match' => [
                'roles' => array_map(fn (array $match): string => $match['role']->title, $entry['matches']),
                'verified_skills' => array_values(array_unique(array_merge(
                    [],
                    ...array_map(fn (array $match): array => $match['verified_skills'], $entry['matches'])
                ))),
            ],
        ];
    }

    private function skillNames(ProjectRole $role, string $importance): array
    {
        return $role->skills
            ->where('importance', $importance)
            ->map(fn (ProjectRoleSkill $skill): string => $skill->skill->name)
            ->values()
            ->all();
    }

    private function studentsOnly(Request $request, OpportunityMatcher $matcher): ?JsonResponse
    {
        if ($request->user()->role !== 'student' || ! $request->user()->studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الصفحة مخصصة للطلاب.',
            ], 403);
        }

        if (! $matcher->canBrowse($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'أكمل ملفك الشخصي أولًا لعرض فرص المشاريع.',
            ], 403);
        }

        return null;
    }
}
