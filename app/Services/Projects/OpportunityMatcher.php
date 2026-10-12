<?php

namespace App\Services\Projects;

use App\Models\ProjectOpportunity;
use App\Models\ProjectRole;
use App\Models\ProjectRoleSkill;
use App\Models\SkillAssessment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Assessments\SkillVerification;
use Illuminate\Support\Collection;

/**
 * مطابقة حتمية قابلة للتفسير بين الطلاب والمشاريع المنشورة.
 *
 * المهارة "مُتحقق منها" فقط إذا اختارها الطالب في ملفه ولها نتيجة "ناجح" محفوظة في skill_assessments
 * لهذه المهارة بعينها (نتيجة كل مهارة مستقلة)، مع شرط إضافي للمهارات العملية: نجاحها في تقييم مكتوب
 * لا يكفي ويلزمها تحقق بشري (SkillVerification). مستوى الإتقان الذي يختاره الطالب بنفسه لا يُحتسب.
 * يطابق الطالب دورًا إذا كان مساره المهني (professional_specialization، لا التخصص الجامعي) متوافقًا
 * مع فئة الدور (config/matching.php) وامتلك جميع مهاراته الإلزامية مُتحققًا منها؛ ويرى المشروع إذا طابق
 * دورًا واحدًا على الأقل. لا توجد عتبة درجات إضافية: النجاح يحدده تقييم كل مهارة.
 */
class OpportunityMatcher
{
    /** يستطيع المستخدم تصفح الفرص إذا كان طالبًا أكمل ملفه (التخصص والمهارات). */
    public function canBrowse(?User $user): bool
    {
        return $user?->role === 'student'
            && $user->studentProfile !== null
            && $user->studentProfile->onboarding_completed;
    }

    /** @return Collection<int, string> أسماء المهارات المُتحقق منها مفهرسة بمعرّف المهارة */
    public function verifiedSkills(StudentProfile $student): Collection
    {
        $selected = $student->studentSkills()->pluck('skill_id');
        $practical = SkillVerification::practicalSkillIds();

        return $student->skillAssessments()
            ->where('status', SkillAssessment::STATUS_PASSED)
            ->whereIn('skill_id', $selected)
            ->with('skill:id,name')
            ->get()
            ->filter(fn (SkillAssessment $assessment): bool => SkillVerification::counts($assessment, $practical))
            ->unique('skill_id')
            ->mapWithKeys(fn (SkillAssessment $assessment): array => [$assessment->skill_id => $assessment->skill->name]);
    }

    /** @return array{status: string, major: ?string, categories: array<int, string>, labels: array<int, string>} */
    public function specialization(StudentProfile $student): array
    {
        return SpecializationRoles::for($student->professional_specialization);
    }

    /**
     * @return array<int, array{opportunity: ProjectOpportunity, matches: array<int, array{role: ProjectRole, verified_skills: array<int, string>}>, roles: array<int, array>}>
     */
    public function visibleFor(StudentProfile $student): array
    {
        $verified = $this->verifiedSkills($student);
        $categories = $this->specialization($student)['categories'];
        if ($verified->isEmpty() || $categories === []) {
            return [];
        }

        return $this->publishedQuery()
            ->latest('published_at')
            ->get()
            ->map(fn (ProjectOpportunity $opportunity): array => $this->entry($opportunity, $verified, $categories))
            ->filter(fn (array $entry): bool => $entry['matches'] !== [])
            ->values()
            ->all();
    }

    /** null تعني أن المشروع غير منشور أو أن الطالب غير مؤهل له؛ يعامل كغير موجود. */
    public function findVisibleFor(StudentProfile $student, int $opportunityId): ?array
    {
        $opportunity = $this->publishedQuery()->whereKey($opportunityId)->first();
        if (! $opportunity) {
            return null;
        }

        $entry = $this->entry($opportunity, $this->verifiedSkills($student), $this->specialization($student)['categories']);

        return $entry['matches'] === [] ? null : $entry;
    }

    /**
     * عدد الطلاب المؤهلين لكل دور (للمؤسسة فقط، دون هويات).
     *
     * @return array<int, int> role_id => count
     */
    public function eligibleCounts(ProjectOpportunity $opportunity): array
    {
        $opportunity->loadMissing('activeVersion.roles.skills');
        $counts = array_fill_keys($opportunity->activeVersion->roles->pluck('id')->all(), 0);
        $practical = SkillVerification::practicalSkillIds();

        StudentProfile::query()
            ->where('onboarding_completed', true)
            ->whereHas('user', fn ($query) => $query->where('role', 'student'))
            ->whereHas('skillAssessments', fn ($query) => $query->where('status', SkillAssessment::STATUS_PASSED))
            ->with(['studentSkills:student_profile_id,skill_id', 'skillAssessments' => fn ($query) => $query->where('status', SkillAssessment::STATUS_PASSED)])
            ->chunk(200, function (Collection $students) use ($opportunity, $practical, &$counts): void {
                foreach ($students as $student) {
                    $categories = $this->specialization($student)['categories'];
                    if ($categories === []) {
                        continue;
                    }
                    $verified = $student->skillAssessments
                        ->filter(fn (SkillAssessment $assessment): bool => SkillVerification::counts($assessment, $practical))
                        ->pluck('skill_id')
                        ->intersect($student->studentSkills->pluck('skill_id'))
                        ->unique()
                        ->values();
                    foreach ($opportunity->activeVersion->roles as $role) {
                        if ($this->roleMatches($role, $verified->all(), $categories)) {
                            $counts[$role->id]++;
                        }
                    }
                }
            });

        return $counts;
    }

    /**
     * حالة كل دور للطالب: هل يطابقه، وهل تخصصه متوافق، وما المهارات الإلزامية غير المتحقق منها بعد.
     * المهارات الإلزامية معروضة أصلًا في الفرصة، فذكر الناقص منها لا يكشف معلومات خاصة.
     */
    private function entry(ProjectOpportunity $opportunity, Collection $verified, array $categories): array
    {
        $verifiedIds = $verified->keys()->all();
        $matches = [];
        $roles = [];
        foreach ($opportunity->activeVersion->roles as $role) {
            $matched = $this->roleMatches($role, $verifiedIds, $categories);
            $mandatory = $role->skills->where('importance', ProjectRoleSkill::MANDATORY);
            $roles[$role->id] = [
                'matched' => $matched,
                'specialization_compatible' => in_array($role->category, $categories, true),
                'missing_skills' => $mandatory
                    ->reject(fn (ProjectRoleSkill $skill): bool => in_array($skill->skill_id, $verifiedIds))
                    ->map(fn (ProjectRoleSkill $skill): string => $skill->skill->name)
                    ->values()
                    ->all(),
            ];
            if ($matched) {
                $matches[] = [
                    'role' => $role,
                    'verified_skills' => $role->skills
                        ->filter(fn (ProjectRoleSkill $skill): bool => in_array($skill->skill_id, $verifiedIds))
                        ->map(fn (ProjectRoleSkill $skill): string => $skill->skill->name)
                        ->values()
                        ->all(),
                ];
            }
        }

        return ['opportunity' => $opportunity, 'matches' => $matches, 'roles' => $roles];
    }

    private function roleMatches(ProjectRole $role, array $verifiedIds, array $categories): bool
    {
        if (! in_array($role->category, $categories, true)) {
            return false;
        }

        $mandatory = $role->skills
            ->where('importance', ProjectRoleSkill::MANDATORY)
            ->pluck('skill_id')
            ->all();

        return $mandatory !== [] && array_diff($mandatory, $verifiedIds) === [];
    }

    private function publishedQuery()
    {
        return ProjectOpportunity::query()
            ->published()
            ->with(['organization:id,organization_name,industry', 'activeVersion.roles.skills.skill:id,name']);
    }
}
