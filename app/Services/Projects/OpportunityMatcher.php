<?php

namespace App\Services\Projects;

use App\Models\ProjectOpportunity;
use App\Models\ProjectRole;
use App\Models\ProjectRoleSkill;
use App\Models\SkillAssessment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Assessments\SkillLevels;
use App\Services\Assessments\SkillVerification;
use Illuminate\Support\Collection;

/**
 * مطابقة حتمية قابلة للتفسير بين الطلاب والمشاريع المنشورة. الظهور منفصل عن الأهلية: كل فرصة منشورة تظهر
 * لكل طالب يحق له التصفح، مع حالة أهلية يحسبها الخادم لكل دور وأسبابها وما يمكن فعله.
 *
 * يستوفي الطالب دورًا فقط إذا:
 * 1. كان مساره المهني (professional_specialization، لا التخصص الجامعي) متوافقًا مع فئة الدور؛
 * 2. وكانت كل مهارة إلزامية مختارة في ملفه؛
 * 3. ولها نتيجة "ناجح" محتسبة (SkillVerification: المهارات العملية تحتاج تحققًا بشريًا)؛
 * 4. وأعلى مستوى مُثبت لها لا يقل عن المستوى الأدنى الذي أكدته المؤسسة للدور.
 * المستوى الذي يطلبه الطالب للتقييم أو يعلنه في ملفه لا يُحتسب. المهارات المرغوبة للعرض فقط.
 *
 * قاعدة التوافق مع النسخ السابقة: مهارة إلزامية بلا min_level (نُشرت قبل إضافة المستويات) تتطلب أي مستوى
 * مُثبت، وهي القاعدة التي اعتُمدت بها النسخة؛ تُعرض كـ level_confirmed = false.
 *
 * رموز الحالة ثابتة وموثقة في التقرير. أي مسار تقديم يُضاف لاحقًا يجب أن يعيد التحقق بـ forStudent/findForStudent.
 */
class OpportunityMatcher
{
    public const ELIGIBLE = 'eligible';

    public const ASSESSMENT_REQUIRED = 'assessment_required';

    public const SKILL_NOT_SELECTED = 'skill_not_selected';

    public const LEVEL_INSUFFICIENT = 'level_insufficient';

    public const SPECIALIZATION_MISMATCH = 'specialization_mismatch';

    public const PROFILE_INCOMPLETE = 'profile_incomplete';

    /** دور بلا مهارة إلزامية من مهارات المنصة: لا يمكن التحقق من متطلباته */
    public const REQUIREMENTS_UNAVAILABLE = 'requirements_unavailable';

    /** البعد عن الأهلية: الأقل أقرب. حالة الدور = أبعد أسبابه، وحالة الفرصة = أقرب أدوارها. */
    private const DISTANCE = [
        self::ELIGIBLE => 0,
        self::LEVEL_INSUFFICIENT => 1,
        self::ASSESSMENT_REQUIRED => 2,
        self::SKILL_NOT_SELECTED => 3,
        self::REQUIREMENTS_UNAVAILABLE => 4,
        self::SPECIALIZATION_MISMATCH => 5,
        self::PROFILE_INCOMPLETE => 6,
    ];

    /** صيغة المستوى المطلوب في الجملة ("يتطلب مستوى متوسطًا") */
    private const REQUIRED_PHRASE = [
        SkillLevels::BEGINNER => 'مستوى مبتدئًا',
        SkillLevels::INTERMEDIATE => 'مستوى متوسطًا',
        SkillLevels::ADVANCED => 'مستوى متقدمًا',
    ];

    /** يستطيع المستخدم تصفح الفرص إذا كان طالبًا أكمل ملفه (الإعداد الأولي). */
    public function canBrowse(?User $user): bool
    {
        return $user?->role === 'student'
            && $user->studentProfile !== null
            && $user->studentProfile->onboarding_completed;
    }

    /**
     * أعلى مستوى مُثبت لكل مهارة مختارة في الملف.
     *
     * @return Collection<int, array{id: int, name: string, level: string, level_label: string}> مفهرسة بمعرّف المهارة
     */
    public function verifiedLevels(StudentProfile $student): Collection
    {
        $selected = $student->studentSkills()->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
        $practical = SkillVerification::practicalSkillIds();

        return $student->skillAssessments()
            ->where('status', SkillAssessment::STATUS_PASSED)
            ->whereIn('skill_id', $selected)
            ->with('skill:id,name')
            ->orderBy('skill_id')
            ->get()
            ->groupBy('skill_id')
            ->map(function (Collection $results) use ($practical): ?array {
                $level = SkillVerification::highestLevel($results, $practical);

                return $level === null ? null : [
                    'id' => (int) $results->first()->skill_id,
                    'name' => $results->first()->skill->name,
                    'level' => $level,
                    'level_label' => SkillLevels::label($level),
                ];
            })
            ->filter();
    }

    /** @return Collection<int, string> أسماء المهارات المُتحقق منها (بأي مستوى) مفهرسة بمعرّف المهارة */
    public function verifiedSkills(StudentProfile $student): Collection
    {
        return $this->verifiedLevels($student)->map(fn (array $skill): string => $skill['name']);
    }

    /** @return array{status: string, key: ?string, label: ?string, categories: array<int, string>, labels: array<int, string>} */
    public function specialization(StudentProfile $student): array
    {
        return SpecializationRoles::for($student->professional_specialization);
    }

    /**
     * كل الفرص المنشورة مع أهلية الطالب لكل دور؛ الأقرب للأهلية أولًا ثم الأحدث نشرًا.
     *
     * @return array<int, array{opportunity: ProjectOpportunity, roles: array<int, array>, eligibility: array, matches: array}>
     */
    public function forStudent(StudentProfile $student): array
    {
        $context = $this->context($student);

        return $this->publishedQuery()
            ->latest('published_at')
            ->latest('id')
            ->get()
            ->map(fn (ProjectOpportunity $opportunity): array => $this->entry($opportunity, $context))
            ->sortBy(fn (array $entry): int => self::DISTANCE[$entry['eligibility']['status']])
            ->values()
            ->all();
    }

    /** null فقط إذا لم تكن الفرصة منشورة (غير موجودة أو معلقة أو مسحوبة)؛ عدم الأهلية لا يخفيها. */
    public function findForStudent(StudentProfile $student, int $opportunityId): ?array
    {
        $opportunity = $this->publishedQuery()->whereKey($opportunityId)->first();

        return $opportunity ? $this->entry($opportunity, $this->context($student)) : null;
    }

    /**
     * عدد الطلاب المستوفين لكل دور (للمؤسسة فقط، دون هويات)، بالقواعد نفسها.
     *
     * @return array<int, int> role_id => count
     */
    public function eligibleCounts(ProjectOpportunity $opportunity): array
    {
        $opportunity->loadMissing('activeVersion.roles.skills.skill:id,name');
        $counts = array_fill_keys($opportunity->activeVersion->roles->pluck('id')->all(), 0);
        $practical = SkillVerification::practicalSkillIds();

        StudentProfile::query()
            ->where('onboarding_completed', true)
            ->whereHas('user', fn ($query) => $query->where('role', 'student'))
            ->whereHas('skillAssessments', fn ($query) => $query->where('status', SkillAssessment::STATUS_PASSED))
            ->with(['studentSkills:student_profile_id,skill_id', 'skillAssessments' => fn ($query) => $query->where('status', SkillAssessment::STATUS_PASSED)])
            ->chunk(200, function (Collection $students) use ($opportunity, $practical, &$counts): void {
                foreach ($students as $student) {
                    $selected = $student->studentSkills->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
                    $verified = $student->skillAssessments
                        ->whereIn('skill_id', $selected)
                        ->groupBy('skill_id')
                        ->map(fn (Collection $results): ?string => SkillVerification::highestLevel($results, $practical))
                        ->filter()
                        ->all();
                    $context = [
                        'specialization' => $this->specialization($student),
                        'selected' => $selected,
                        'verified' => $verified,
                        'verified_unselected' => [],
                        'attempted' => [],
                        'practical' => $practical,
                    ];
                    foreach ($opportunity->activeVersion->roles as $role) {
                        if ($this->evaluateRole($role, $context)['status'] === self::ELIGIBLE) {
                            $counts[$role->id]++;
                        }
                    }
                }
            });

        return $counts;
    }

    /** ما يلزم لتقييم أدوار أي فرصة لطالب واحد، يُحسب مرة لكل طلب. */
    private function context(StudentProfile $student): array
    {
        $selected = $student->studentSkills()->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
        $practical = SkillVerification::practicalSkillIds();
        $results = $student->skillAssessments()->get()->groupBy('skill_id');
        $highest = $results->map(fn (Collection $items): ?string => SkillVerification::highestLevel($items, $practical))->filter();

        return [
            'specialization' => $this->specialization($student),
            'selected' => $selected,
            // المستوى المُثبت يُحتسب فقط لمهارة مختارة في الملف
            'verified' => $highest->only($selected)->all(),
            'verified_unselected' => $highest->except($selected)->all(),
            'attempted' => $results->keys()->map(fn ($id): int => (int) $id)->all(),
            'practical' => $practical,
        ];
    }

    private function entry(ProjectOpportunity $opportunity, array $context): array
    {
        $roles = [];
        $best = null;
        $matches = [];
        foreach ($opportunity->activeVersion->roles as $role) {
            $evaluation = $this->evaluateRole($role, $context);
            $roles[$role->id] = $evaluation;
            if ($best === null || self::DISTANCE[$evaluation['status']] < self::DISTANCE[$best['evaluation']['status']]) {
                $best = ['role' => $role, 'evaluation' => $evaluation];
            }
            if ($evaluation['status'] === self::ELIGIBLE) {
                $matches[] = [
                    'role' => $role,
                    // مهارات الدور (الإلزامية والمرغوبة) المثبتة لدى الطالب
                    'verified_skills' => $role->skills
                        ->filter(fn (ProjectRoleSkill $skill): bool => isset($context['verified'][(int) $skill->skill_id]))
                        ->map(fn (ProjectRoleSkill $skill): string => $skill->skill->name)
                        ->values()
                        ->all(),
                ];
            }
        }

        return [
            'opportunity' => $opportunity,
            'roles' => $roles,
            'matches' => $matches,
            // الأهلية على مستوى الفرصة = الدور الأقرب للأهلية
            'eligibility' => [
                'status' => $best['evaluation']['status'] ?? self::REQUIREMENTS_UNAVAILABLE,
                'message' => $best['evaluation']['message'] ?? 'لا يمكن التحقق من متطلبات هذه الفرصة على المنصة حاليًا.',
                'role_id' => $best['role']->id ?? null,
                'role_title' => $best['role']->title ?? null,
                'action' => $best['evaluation']['action'] ?? null,
                'eligible_roles' => array_map(fn (array $match): string => $match['role']->title, $matches),
            ],
        ];
    }

    /**
     * أهلية الطالب لدور واحد: الحالة، رسالة عربية، كل سبب مع المهارة والمستويين، والإجراء المقترح.
     * المهارات الإلزامية ومستوياتها معروضة أصلًا في الفرصة، فذكرها لا يكشف معلومات خاصة.
     */
    private function evaluateRole(ProjectRole $role, array $context): array
    {
        $spec = $context['specialization'];
        $skills = $role->skills
            ->where('importance', ProjectRoleSkill::MANDATORY)
            ->values()
            ->map(function (ProjectRoleSkill $requirement) use ($context): array {
                $id = (int) $requirement->skill_id;
                $verified = $context['verified'][$id] ?? null;
                // NULL = نسخة منشورة قبل إضافة المستويات: أي مستوى مُثبت
                $required = $requirement->min_level ?? SkillLevels::BEGINNER;

                return [
                    'id' => $id,
                    'name' => $requirement->skill->name,
                    'min_level' => $requirement->min_level,
                    'min_level_label' => SkillLevels::label($requirement->min_level),
                    'level_confirmed' => $requirement->min_level !== null,
                    'selected' => in_array($id, $context['selected'], true),
                    'verified_level' => $verified,
                    'verified_level_label' => SkillLevels::label($verified),
                    'met' => $verified !== null && SkillLevels::atLeast($verified, $required),
                    'required' => $required,
                ];
            })
            ->all();

        if ($spec['status'] !== SpecializationRoles::COMPATIBLE) {
            $reasons = [$this->reason(self::PROFILE_INCOMPLETE, null, $spec['status'] === SpecializationRoles::UNRECOGNIZED
                ? 'مسارك المهني المسجل غير معروف. اختر مسارك المهني من القائمة لمعرفة مدى توافقك مع هذه الفرصة.'
                : 'حدد مسارك المهني في ملفك أولًا لمعرفة مدى توافقك مع هذه الفرصة.', ['type' => 'profile', 'label' => 'تحديد المسار المهني'])];
        } elseif (! in_array($role->category, $spec['categories'], true)) {
            $category = ProjectStaffing::ROLE_CATEGORIES[$role->category] ?? $role->category;
            $reasons = [$this->reason(self::SPECIALIZATION_MISMATCH, null, "هذا الدور ({$category}) لا يتوافق مع مسارك المهني الحالي ({$spec['label']}).", null)];
        } elseif ($skills === []) {
            $reasons = [$this->reason(self::REQUIREMENTS_UNAVAILABLE, null, 'لا يمكن التحقق من متطلبات هذا الدور على المنصة حاليًا.', null)];
        } else {
            $reasons = [];
            foreach ($skills as $skill) {
                if (! $skill['met']) {
                    $reasons[] = $this->skillReason($skill, $context);
                }
            }
        }

        $status = self::ELIGIBLE;
        foreach ($reasons as $reason) {
            if (self::DISTANCE[$reason['code']] > self::DISTANCE[$status]) {
                $status = $reason['code'];
            }
        }
        $lead = collect($reasons)->firstWhere('code', $status);

        return [
            'status' => $status,
            'message' => $lead['message'] ?? 'أنت تستوفي متطلبات المهارات والمستوى لهذا الدور.',
            'reasons' => $reasons,
            'action' => $lead['action'] ?? null,
            'skills' => array_map(fn (array $skill): array => array_diff_key($skill, ['required' => true]), $skills),
        ];
    }

    private function skillReason(array $skill, array $context): array
    {
        $name = $skill['name'];
        $required = $skill['required'];
        $assess = [
            'type' => 'assessment',
            'skill_id' => $skill['id'],
            'level' => $required,
            'label' => "ابدأ اختبار {$name} (".SkillLevels::label($required).')',
        ];

        if (! $skill['selected']) {
            $verifiedElsewhere = $context['verified_unselected'][$skill['id']] ?? null;

            return $verifiedElsewhere !== null
                ? $this->reason(self::SKILL_NOT_SELECTED, $skill, "مهارة {$name} مثبتة لديك لكنها ليست ضمن مهارات ملفك. أضفها إلى ملفك لتُحتسب في هذا الدور.", ['type' => 'profile', 'label' => 'إضافة المهارة إلى ملفي'])
                // بدء تقييم المهارة يضيفها إلى الملف تلقائيًا
                : $this->reason(self::SKILL_NOT_SELECTED, $skill, "مهارة {$name} ليست ضمن مهارات ملفك بعد. ابدأ اختبار المهارة لإضافتها ومعرفة مدى توافقك مع هذه الفرصة.", $assess);
        }

        if ($skill['verified_level'] === null) {
            if (in_array($skill['id'], $context['practical'], true)) {
                return $this->reason(self::ASSESSMENT_REQUIRED, $skill, "مهارة {$name} عملية ولا يكفي الاختبار المكتوب لإثباتها؛ تحتاج تحققًا عمليًا من إدارة المنصة (مثل مراجعة أعمالك).", null);
            }

            return in_array($skill['id'], $context['attempted'], true)
                ? $this->reason(self::ASSESSMENT_REQUIRED, $skill, "لم تجتز اختبار مهارة {$name} بعد. أعد اختبار المهارة لمعرفة مدى توافقك مع هذه الفرصة.", $assess)
                : $this->reason(self::ASSESSMENT_REQUIRED, $skill, "لم تختبر مهارة {$name} بعد. ابدأ اختبار المهارة لمعرفة مدى توافقك مع هذه الفرصة.", $assess);
        }

        return $this->reason(
            self::LEVEL_INSUFFICIENT,
            $skill,
            "مستواك المثبت في {$name} هو {$skill['verified_level_label']}، بينما يتطلب هذا الدور ".self::REQUIRED_PHRASE[$required].'.',
            $assess
        );
    }

    private function reason(string $code, ?array $skill, string $message, ?array $action): array
    {
        return [
            'code' => $code,
            'skill' => $skill ? ['id' => $skill['id'], 'name' => $skill['name']] : null,
            'required_level' => $skill['min_level'] ?? null,
            'required_level_label' => $skill['min_level_label'] ?? null,
            'verified_level' => $skill['verified_level'] ?? null,
            'verified_level_label' => $skill['verified_level_label'] ?? null,
            'message' => $message,
            'action' => $action,
        ];
    }

    private function publishedQuery()
    {
        return ProjectOpportunity::query()
            ->published()
            ->with(['organization:id,organization_name,industry', 'activeVersion.roles.skills.skill:id,name']);
    }
}
