<?php

namespace App\Services\Projects;

use App\Models\ProjectApplication;
use App\Models\ProjectOpportunity;
use App\Models\ProjectRole;
use App\Models\ProjectRoleSkill;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\StudentProfile;
use App\Services\Assessments\SkillLevels;
use App\Services\Assessments\SkillVerification;
use Illuminate\Support\Collection;

/**
 * ترتيب المرشحين لمشروع (للمؤسسة المالكة فقط). المرشحون هم من قدّموا على المشروع: التقديم موافقة الطالب على
 * مشاركة ملفه مع المؤسسة، أما بقية المستوفين فيُعرض عددهم فقط كما في السابق.
 *
 * الأهلية لكل دور = قواعد OpportunityMatcher نفسها (المسار المهني + كل مهارة إلزامية مثبتة بالمستوى الأدنى).
 * درجة التوافق (0–100) تُحسب للأدوار المستوفاة فقط من عوامل قابلة للتفسير، وكل عامل يذكر نقاطه وسببه:
 *  - assessment   (40): مستوى الاختبار المثبت لكل مهارة إلزامية مقارنة بالمطلوب (أعلى من المطلوب = أفضل)
 *  - proficiency  (10): المستوى الذي أعلنه الطالب في ملفه للمهارات الإلزامية (مؤشر مكمل لا يغني عن الاختبار)
 *  - desirable    (15): المهارات المرغوبة: مثبتة = كاملة، مضافة للملف فقط = نصف؛ لا يُحتسب إن لم توجد مهارات مرغوبة
 *  - evidence     (15): أعمال المعرض والشهادات وحساب GitHub والروابط والتوصيات
 *  - availability (20): ساعات التفرغ مقابل الحد الأدنى للمشروع (12) + عدم الانشغال بفريق مشروع آخر قائم (8)
 * البيانات الناقصة تُعطي صفرًا لعاملها مع وسم missing، ولا توقف المطابقة. الدرجة مؤشر وليست ضمانًا للاختيار.
 */
class CandidateRanking
{
    public const WEIGHTS = [
        'assessment' => 40,
        'proficiency' => 10,
        'desirable' => 15,
        'evidence' => 15,
        'availability' => 20,
    ];

    private const HOURS_POINTS = 12;

    private const COMMITMENT_POINTS = 8;

    public function __construct(private readonly OpportunityMatcher $matcher) {}

    /**
     * @return array{roles: array, candidates: array, ineligible: array, ranking: array<int, array<int, int>>, summary: array}
     */
    public function forProject(ProjectOpportunity $opportunity): array
    {
        $opportunity->loadMissing(['activeVersion.roles.skills.skill:id,name']);
        $roles = $opportunity->activeVersion->roles;
        $team = $opportunity->currentTeam()?->load('members');
        $memberRoles = $team ? $team->members->pluck('project_role_id', 'student_profile_id')->all() : [];

        $applications = $opportunity->applications()
            ->where('status', '!=', ProjectApplication::STATUS_WITHDRAWN)
            ->with([
                'studentProfile.user:id,name',
                'studentProfile.studentSkills',
                'studentProfile.skillAssessments',
                'studentProfile.profileDetails',
            ])
            ->oldest()
            ->get();

        $commitments = $this->otherCommitments($opportunity, $applications->pluck('student_profile_id')->all());
        $practical = SkillVerification::practicalSkillIds();

        $candidates = [];
        $ineligible = [];
        foreach ($applications as $application) {
            $student = $application->studentProfile;
            $context = $this->matcher->contextFromLoaded($student, $practical);
            $evaluations = $this->matcher->evaluateRoles($opportunity, $context);
            $declared = $student->studentSkills->pluck('proficiency', 'skill_id')->mapWithKeys(fn ($level, $id) => [(int) $id => $level])->all();

            $roleScores = [];
            foreach ($roles as $role) {
                if ($evaluations[$role->id]['status'] === OpportunityMatcher::ELIGIBLE) {
                    $roleScores[] = $this->score($role, $context, $declared, $student, $opportunity, $commitments[$student->id] ?? 0);
                }
            }

            $base = [
                'application' => $this->application($application),
                'student' => $this->student($student, $commitments[$student->id] ?? 0),
                'team' => [
                    'is_member' => isset($memberRoles[$student->id]),
                    'role_id' => $memberRoles[$student->id] ?? null,
                ],
            ];

            if ($roleScores === []) {
                $ineligible[] = $base + ['eligibility' => $this->closestGap($roles, $evaluations)];

                continue;
            }

            usort($roleScores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $candidates[] = $base + [
                'best_role_id' => $roleScores[0]['role_id'],
                'best_score' => $roleScores[0]['score'],
                'roles' => $roleScores,
            ];
        }

        // الترتيب يحسبه الخادم: لكل دور، الأعلى درجة ثم الأسبق تقديمًا
        $ranking = [];
        foreach ($roles as $role) {
            $entries = [];
            foreach ($candidates as $candidate) {
                foreach ($candidate['roles'] as $score) {
                    if ($score['role_id'] === $role->id) {
                        $entries[] = ['id' => $candidate['application']['id'], 'score' => $score['score'], 'at' => $candidate['application']['applied_at']];
                    }
                }
            }
            usort($entries, fn (array $a, array $b): int => [$b['score'], $a['at']] <=> [$a['score'], $b['at']]);
            $ranking[$role->id] = array_column($entries, 'id');
        }
        usort($candidates, fn (array $a, array $b): int => [$b['best_score'], $a['application']['applied_at']] <=> [$a['best_score'], $b['application']['applied_at']]);

        return [
            'roles' => $this->roleSummaries($opportunity, $roles, $candidates, $memberRoles),
            'candidates' => $candidates,
            'ineligible' => $ineligible,
            'ranking' => $ranking,
            'summary' => $this->summary($roles, $applications, $candidates),
        ];
    }

    /** درجة طالب مستوفٍ لدور واحد مع عوامل مفسرة ومقارنة المهارات. */
    private function score(ProjectRole $role, array $context, array $declared, StudentProfile $student, ProjectOpportunity $opportunity, int $commitments): array
    {
        $mandatory = $role->skills->where('importance', ProjectRoleSkill::MANDATORY)->values();
        $desirable = $role->skills->where('importance', ProjectRoleSkill::DESIRABLE)->values();

        $factors = [
            $this->assessmentFactor($mandatory, $context),
            $this->proficiencyFactor($mandatory, $declared),
            $this->desirableFactor($desirable, $context, $declared),
            $this->evidenceFactor($student),
            $this->availabilityFactor($student, $opportunity, $commitments),
        ];

        $applicable = array_filter($factors, fn (array $factor): bool => $factor['status'] !== 'not_applicable');
        $max = array_sum(array_column($applicable, 'max'));
        $points = array_sum(array_column($applicable, 'points'));
        $score = $max > 0 ? (int) round($points / $max * 100) : 0;

        return [
            'role_id' => $role->id,
            'score' => $score,
            'band' => $score >= 80 ? 'high' : ($score >= 60 ? 'good' : 'fair'),
            'factors' => $factors,
            'skills' => $this->skillComparison($mandatory, $desirable, $context, $declared),
        ];
    }

    private function assessmentFactor(Collection $mandatory, array $context): array
    {
        $max = self::WEIGHTS['assessment'];
        if ($mandatory->isEmpty()) {
            return $this->factor('assessment', 'نتائج التقييم', 0, $max, 'not_applicable', 'لا توجد مهارات إلزامية لهذا الدور.');
        }

        $total = 0.0;
        $above = 0;
        foreach ($mandatory as $requirement) {
            $verified = $context['verified'][(int) $requirement->skill_id] ?? null;
            $margin = SkillLevels::rank($verified) - SkillLevels::rank($requirement->min_level ?? SkillLevels::BEGINNER);
            $above += $margin > 0 ? 1 : 0;
            // المستوى المطلوب بالضبط = 0.8، وكل مستوى أعلى يضيف 0.1 حتى 1
            $total += $margin < 0 ? 0 : min(1, 0.8 + 0.1 * $margin);
        }
        $count = $mandatory->count();
        $points = (int) round($max * $total / $count);
        $detail = "اجتاز اختبارات {$count} من {$count} مهارات إلزامية بالمستوى المطلوب";
        $detail .= $above > 0 ? "، منها {$above} بمستوى أعلى من المطلوب." : '.';

        return $this->factor('assessment', 'نتائج التقييم', $points, $max, $above > 0 ? 'strong' : 'good', $detail);
    }

    private function proficiencyFactor(Collection $mandatory, array $declared): array
    {
        $max = self::WEIGHTS['proficiency'];
        if ($mandatory->isEmpty()) {
            return $this->factor('proficiency', 'المستوى المعلن', 0, $max, 'not_applicable', 'لا توجد مهارات إلزامية لهذا الدور.');
        }

        $met = $mandatory->filter(fn (ProjectRoleSkill $requirement): bool => SkillLevels::atLeast(
            $declared[(int) $requirement->skill_id] ?? null,
            $requirement->min_level ?? SkillLevels::BEGINNER
        ))->count();
        $count = $mandatory->count();
        $points = (int) round($max * $met / $count);

        return $this->factor(
            'proficiency',
            'المستوى المعلن',
            $points,
            $max,
            $met === $count ? 'strong' : ($met > 0 ? 'partial' : 'weak'),
            "مستواه المعلن في ملفه يطابق المطلوب في {$met} من {$count} مهارات إلزامية (تقدير ذاتي مكمل للاختبار)."
        );
    }

    private function desirableFactor(Collection $desirable, array $context, array $declared): array
    {
        $max = self::WEIGHTS['desirable'];
        if ($desirable->isEmpty()) {
            return $this->factor('desirable', 'المهارات المرغوبة', 0, $max, 'not_applicable', 'لم تحدد المؤسسة مهارات مرغوبة لهذا الدور.');
        }

        $verified = [];
        $listed = [];
        foreach ($desirable as $skill) {
            $id = (int) $skill->skill_id;
            if (isset($context['verified'][$id])) {
                $verified[] = $skill->skill->name;
            } elseif (isset($declared[$id])) {
                $listed[] = $skill->skill->name;
            }
        }
        $count = $desirable->count();
        $points = (int) round($max * (count($verified) + 0.5 * count($listed)) / $count);
        $parts = [];
        if ($verified !== []) {
            $parts[] = 'مثبتة: '.implode('، ', $verified);
        }
        if ($listed !== []) {
            $parts[] = 'في ملفه دون اختبار: '.implode('، ', $listed);
        }
        $have = count($verified) + count($listed);

        return $this->factor(
            'desirable',
            'المهارات المرغوبة',
            $points,
            $max,
            $have === 0 ? 'weak' : (count($verified) === $count ? 'strong' : 'partial'),
            $have === 0
                ? "لا يملك أيًا من المهارات المرغوبة ({$count})."
                : "لديه {$have} من {$count} مهارات مرغوبة — ".implode('؛ ', $parts).'.'
        );
    }

    private function evidenceFactor(StudentProfile $student): array
    {
        $details = $student->profileDetails;
        $portfolio = count(array_filter((array) ($details?->portfolio ?? []), 'is_array'));
        $certifications = count(array_filter((array) ($details?->certifications ?? []), 'is_array'));
        $links = count(array_filter((array) ($details?->external_links ?? []), 'is_array'));
        $testimonials = count(array_filter((array) ($details?->testimonials ?? []), 'is_array'));
        $github = filled($student->github_url);
        $linkedin = filled($student->linkedin_url);

        $points = min(2, $portfolio) * 3 + min(2, $certifications) * 2 + ($github ? 3 : 0)
            + (($linkedin || $links > 0 || $testimonials > 0) ? 2 : 0);

        $parts = [];
        if ($portfolio > 0) {
            $parts[] = "{$portfolio} ".($portfolio === 1 ? 'عمل' : 'أعمال').' في المعرض';
        }
        if ($certifications > 0) {
            $parts[] = "{$certifications} ".($certifications === 1 ? 'شهادة' : 'شهادات');
        }
        if ($github) {
            $parts[] = 'حساب GitHub';
        }
        if ($testimonials > 0) {
            $parts[] = "{$testimonials} ".($testimonials === 1 ? 'توصية' : 'توصيات');
        }
        if ($linkedin || $links > 0) {
            $parts[] = 'روابط مهنية';
        }

        $max = self::WEIGHTS['evidence'];

        return $this->factor(
            'evidence',
            'أدلة الأعمال',
            $points,
            $max,
            $parts === [] ? 'missing' : ($points >= 10 ? 'strong' : 'partial'),
            $parts === [] ? 'لم يضف أعمالًا أو شهادات أو روابط إلى ملفه بعد.' : implode('، ', $parts).'.'
        );
    }

    private function availabilityFactor(StudentProfile $student, ProjectOpportunity $opportunity, int $commitments): array
    {
        $hours = $student->weekly_availability_hours;
        $required = $opportunity->min_weekly_hours;

        if ($hours === null) {
            $hoursPoints = 0;
            $hoursText = 'لم يحدد ساعات تفرغه الأسبوعية';
        } elseif ($required === null) {
            $hoursPoints = self::HOURS_POINTS;
            $hoursText = "متفرغ {$hours} ساعة أسبوعيًا (المشروع لم يحدد حدًا أدنى)";
        } else {
            $hoursPoints = (int) round(self::HOURS_POINTS * min(1, $hours / max(1, $required)));
            $hoursText = $hours >= $required
                ? "متفرغ {$hours} ساعة أسبوعيًا، والمطلوب {$required}"
                : "متفرغ {$hours} ساعة أسبوعيًا فقط، والمطلوب {$required}";
        }

        $commitmentPoints = $commitments === 0 ? self::COMMITMENT_POINTS : 0;
        $commitmentText = $commitments === 0 ? 'غير مرتبط بفريق مشروع آخر' : 'عضو حاليًا في فريق مشروع آخر';
        $points = $hoursPoints + $commitmentPoints;
        $max = self::WEIGHTS['availability'];

        $status = match (true) {
            $hours === null => 'missing',
            $points === $max => 'strong',
            $points >= $max / 2 => 'partial',
            default => 'weak',
        };

        return $this->factor('availability', 'التوفر', $points, $max, $status, "{$hoursText}؛ {$commitmentText}.");
    }

    /** مقارنة كل مهارة في الدور: المطلوب، المثبت بالاختبار، والمعلن في الملف. */
    private function skillComparison(Collection $mandatory, Collection $desirable, array $context, array $declared): array
    {
        return $mandatory->concat($desirable)->map(function (ProjectRoleSkill $requirement) use ($context, $declared): array {
            $id = (int) $requirement->skill_id;
            $verified = $context['verified'][$id] ?? null;
            $mandatory = $requirement->importance === ProjectRoleSkill::MANDATORY;
            $required = $mandatory ? ($requirement->min_level ?? SkillLevels::BEGINNER) : null;

            return [
                'name' => $requirement->skill->name,
                'importance' => $requirement->importance,
                'required_level' => $required,
                'required_level_label' => SkillLevels::label($required),
                'verified_level' => $verified,
                'verified_level_label' => SkillLevels::label($verified),
                'declared_level' => $declared[$id] ?? null,
                'declared_level_label' => SkillLevels::label($declared[$id] ?? null),
                'met' => $mandatory ? SkillLevels::atLeast($verified, $required) : $verified !== null,
            ];
        })->values()->all();
    }

    private function factor(string $key, string $label, int $points, int $max, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'points' => $points, 'max' => $max, 'status' => $status, 'detail' => $detail];
    }

    /** سبب عدم الأهلية للمؤسسة من الدور الأقرب، دون رسائل الطالب الموجهة إليه. */
    private function closestGap(Collection $roles, array $evaluations): array
    {
        $best = null;
        foreach ($roles as $role) {
            $evaluation = $evaluations[$role->id];
            if ($best === null || count(array_filter($evaluation['skills'], fn (array $skill): bool => ! $skill['met']))
                < count(array_filter($evaluations[$best->id]['skills'], fn (array $skill): bool => ! $skill['met']))) {
                $best = $role;
            }
        }
        $evaluation = $best ? $evaluations[$best->id] : null;

        return [
            'status' => $evaluation['status'] ?? OpportunityMatcher::REQUIREMENTS_UNAVAILABLE,
            'role_id' => $best?->id,
            'role_title' => $best?->title,
            'missing_skills' => $evaluation ? array_values(array_map(
                fn (array $skill): array => [
                    'name' => $skill['name'],
                    'required_level_label' => $skill['min_level_label'] ?? SkillLevels::label(SkillLevels::BEGINNER),
                    'verified_level_label' => $skill['verified_level_label'],
                ],
                array_filter($evaluation['skills'], fn (array $skill): bool => ! $skill['met'])
            )) : [],
        ];
    }

    /** @return array<int, int> student_profile_id => عدد فرق المشاريع الأخرى القائمة التي ينتمي إليها */
    private function otherCommitments(ProjectOpportunity $opportunity, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return ProjectTeamMember::query()
            ->whereIn('student_profile_id', $studentIds)
            ->whereHas('team', fn ($query) => $query
                ->whereIn('status', ProjectTeam::COMMITTED_STATUSES)
                ->where('project_opportunity_id', '!=', $opportunity->id))
            ->selectRaw('student_profile_id, count(*) as total')
            ->groupBy('student_profile_id')
            ->pluck('total', 'student_profile_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    private function application(ProjectApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'preferred_role_id' => $application->preferred_role_id,
            'note' => $application->note,
            'applied_at' => $application->created_at?->toIso8601String(),
        ];
    }

    private function student(StudentProfile $student, int $commitments): array
    {
        $specialization = $this->matcher->specialization($student);

        return [
            'id' => $student->id,
            'name' => $student->user?->name,
            'status' => $student->status,
            'university' => $student->university,
            'academic_major' => $student->academic_major,
            'specialization' => $specialization['label'] ?? null,
            'weekly_availability_hours' => $student->weekly_availability_hours,
            'other_active_teams' => $commitments,
            'github_url' => $student->github_url,
            'linkedin_url' => $student->linkedin_url,
            'image_url' => $student->image_path
                ? route('api.profile-images.student', ['filename' => basename(str_replace('\\', '/', $student->image_path))])
                : null,
        ];
    }

    private function roleSummaries(ProjectOpportunity $opportunity, Collection $roles, array $candidates, array $memberRoles): array
    {
        $counts = $this->matcher->eligibleCounts($opportunity);
        $active = collect($candidates)->filter(fn (array $candidate): bool => in_array($candidate['application']['status'], ProjectApplication::ACTIVE_STATUSES, true));

        return $roles->map(function (ProjectRole $role) use ($counts, $active, $memberRoles): array {
            $eligibleApplicants = $active->filter(fn (array $candidate): bool => collect($candidate['roles'])->contains('role_id', $role->id))->count();

            return [
                'id' => $role->id,
                'title' => $role->title,
                'category' => $role->category,
                'category_label' => ProjectStaffing::ROLE_CATEGORIES[$role->category] ?? $role->category,
                'headcount' => $role->headcount,
                'eligible_applicants' => $eligibleApplicants,
                // كل الطلاب المستوفين على المنصة (عدد فقط، دون هويات من لم يقدّم)
                'eligible_students' => $counts[$role->id] ?? 0,
                'selected' => count(array_filter($memberRoles, fn ($roleId): bool => (int) $roleId === $role->id)),
                'insufficient' => $eligibleApplicants < $role->headcount,
                'mandatory' => $role->skills->where('importance', ProjectRoleSkill::MANDATORY)->map(fn (ProjectRoleSkill $skill): array => [
                    'name' => $skill->skill->name,
                    'min_level' => $skill->min_level,
                    'min_level_label' => SkillLevels::label($skill->min_level ?? SkillLevels::BEGINNER),
                ])->values()->all(),
                'desirable' => $role->skills->where('importance', ProjectRoleSkill::DESIRABLE)->map(fn (ProjectRoleSkill $skill): string => $skill->skill->name)->values()->all(),
            ];
        })->values()->all();
    }

    private function summary(Collection $roles, Collection $applications, array $candidates): array
    {
        $activeCandidates = array_filter($candidates, fn (array $candidate): bool => in_array($candidate['application']['status'], ProjectApplication::ACTIVE_STATUSES, true));
        $shortRoles = $roles->filter(fn (ProjectRole $role): bool => count(array_filter(
            $activeCandidates,
            fn (array $candidate): bool => collect($candidate['roles'])->contains('role_id', $role->id)
        )) < $role->headcount);

        return [
            'applications' => $applications->count(),
            'active_applications' => $applications->filter(fn (ProjectApplication $application): bool => $application->isActive())->count(),
            'eligible_applicants' => count($activeCandidates),
            'ineligible_applicants' => $applications->count() - count($candidates),
            'required_team_size' => (int) $roles->sum('headcount'),
            'insufficient' => $shortRoles->isNotEmpty(),
            'short_roles' => $shortRoles->pluck('title')->values()->all(),
        ];
    }
}
