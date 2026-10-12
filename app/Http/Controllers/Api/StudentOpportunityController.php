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
 * فرص المشاريع للطلاب. الفلترة تتم هنا في الخادم (التخصص + المهارات المتحقق منها بتقييم ناجح):
 * المشروع غير المنشور أو غير المطابق يُعامل كغير موجود (404) حتى عند طلبه مباشرة بالمعرّف.
 * لا تُعاد بيانات المؤسسة الخاصة مثل التكلفة والميزانية والدفعات ولا محادثة الاكتشاف.
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
                    $matcher->visibleFor($student)
                ),
                'verified_skills' => $matcher->verifiedSkills($student)->values()->all(),
                // سبب غياب الفرص إن كان التخصص غير مرتبط بأي دور (لا يُستعاض عنه بتخفيف شرط المهارات)
                'specialization' => $matcher->specialization($student),
            ],
        ]);
    }

    public function show(Request $request, int $opportunity, OpportunityMatcher $matcher): JsonResponse
    {
        if ($response = $this->studentsOnly($request, $matcher)) {
            return $response;
        }

        $entry = $matcher->findVisibleFor($request->user()->studentProfile, $opportunity);
        if ($entry === null) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الفرصة غير متاحة لك حاليًا.',
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
        $matchedRoleIds = array_map(fn (array $match): int => $match['role']->id, $entry['matches']);

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
            'roles' => $opportunity->activeVersion->roles->map(fn (ProjectRole $role): array => [
                'id' => $role->id,
                'category' => $role->category,
                'category_label' => ProjectStaffing::ROLE_CATEGORIES[$role->category] ?? $role->category,
                'title' => $role->title,
                'responsibilities' => $role->responsibilities,
                'headcount' => $role->headcount,
                'mandatory_skills' => $this->skillNames($role, ProjectRoleSkill::MANDATORY),
                'desirable_skills' => $this->skillNames($role, ProjectRoleSkill::DESIRABLE),
                'matched' => in_array($role->id, $matchedRoleIds, true),
                'specialization_compatible' => $entry['roles'][$role->id]['specialization_compatible'] ?? false,
                'missing_skills' => $entry['roles'][$role->id]['missing_skills'] ?? [],
            ])->all(),
            // سبب ظهور المشروع: الأدوار المطابقة والمهارات المُتحقق منها فعلًا
            'match' => [
                'roles' => array_map(fn (array $match): string => $match['role']->title, $entry['matches']),
                'verified_skills' => array_values(array_unique(array_merge(
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
