<?php

namespace App\Services\Projects;

use App\Models\Skill;
use App\Services\Assessments\SkillVerification;

/**
 * متطلبات الفريق المنظمة في المقترح (staffing_requirements): فئة الدور، المسؤوليات، المبرر،
 * والمهارات الإلزامية والمرغوبة. المهارات تُطابق مع جدول skills الموجود فقط؛ مهارة غير
 * موجودة فيه تُعرض للمؤسسة لكنها لا تدخل في مطابقة الطلاب لأنه لا يمكن التحقق منها. وكل مهارة في
 * المنصة يمكن تقييمها كتابيًا، عدا المهارات العملية (مثل Figma) التي يلزمها تحقق بشري، فتُبلَّغ المؤسسة بذلك.
 */
final class ProjectStaffing
{
    public const ROLE_CATEGORIES = [
        'mobile_developer' => 'مطوّر تطبيقات جوال',
        'backend_developer' => 'مطوّر الخدمات الخلفية (Backend)',
        'frontend_developer' => 'مطوّر واجهات الويب (Frontend)',
        'fullstack_developer' => 'مطوّر ويب متكامل (Full-stack)',
        'ui_ux_designer' => 'مصمم واجهات وتجربة مستخدم',
        'data_specialist' => 'مختص بيانات',
        'qa_tester' => 'مختبر جودة البرمجيات',
        'other' => 'دور آخر',
    ];

    public const MAX_ROLES = 8;

    public const MAX_SKILLS_PER_ROLE = 10;

    /**
     * تنظيف نصي لأدوار يولدها الذكاء الاصطناعي (دون قاعدة البيانات). الأدوار غير الصالحة تُحذف منفردة.
     *
     * @return array<int, array{category: string, title: string, responsibilities: string, rationale: string, headcount: int, mandatory_skills: array<int, string>, desirable_skills: array<int, string>}>
     */
    public static function sanitize(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        $roles = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = self::text($item['title'] ?? null, 120);
            $responsibilities = self::text($item['responsibilities'] ?? null, 2000);
            if ($title === '' || $responsibilities === '') {
                continue;
            }

            $mandatory = self::skillNames($item['mandatory_skills'] ?? []);
            $desirable = array_values(array_diff(self::skillNames($item['desirable_skills'] ?? []), $mandatory));
            $category = is_string($item['category'] ?? null) && isset(self::ROLE_CATEGORIES[$item['category']])
                ? $item['category']
                : 'other';

            $roles[] = [
                'category' => $category,
                'title' => $title,
                'responsibilities' => $responsibilities,
                'rationale' => self::text($item['rationale'] ?? null, 2000),
                'headcount' => max(1, min(5, (int) ($item['headcount'] ?? 1))),
                'mandatory_skills' => $mandatory,
                'desirable_skills' => $desirable,
            ];

            if (count($roles) === self::MAX_ROLES) {
                break;
            }
        }

        return $roles;
    }

    /**
     * متطلبات الفريق للمقترح مع ربط المهارات بجدول skills.
     *
     * @return array{roles: array<int, array>, matchable_roles: int, unmapped_skills: array<int, string>, skills_requiring_human_verification: array<int, string>}
     */
    public static function fromProposal(?array $proposal): array
    {
        $roles = self::sanitize($proposal['staffing_requirements'] ?? null);
        $catalog = self::catalog();
        $practical = SkillVerification::practicalSkillIds();
        $unmapped = [];
        $untested = [];

        foreach ($roles as &$role) {
            foreach (['mandatory_skills', 'desirable_skills'] as $key) {
                $role[$key] = array_map(function (string $name) use ($catalog, $practical, $key, &$unmapped, &$untested): array {
                    $skill = $catalog[mb_strtolower($name)] ?? null;
                    // assessable: يكفي التقييم المكتوب لإثباتها في المطابقة
                    $hasTest = $skill !== null && ! in_array($skill['id'], $practical, true);
                    if ($skill === null) {
                        $unmapped[] = $name;
                    } elseif (! $hasTest && $key === 'mandatory_skills') {
                        $untested[] = $skill['name'];
                    }

                    return ['id' => $skill['id'] ?? null, 'name' => $skill['name'] ?? $name, 'assessable' => $hasTest];
                }, $role[$key]);
            }
            $role['category_label'] = self::ROLE_CATEGORIES[$role['category']];
            // الدور قابل للمطابقة فقط إذا كانت له مهارة إلزامية واحدة على الأقل موجودة في المنصة
            $role['matchable'] = array_filter($role['mandatory_skills'], fn (array $skill): bool => $skill['id'] !== null) !== [];
        }
        unset($role);

        return [
            'roles' => $roles,
            'matchable_roles' => count(array_filter($roles, fn (array $role): bool => $role['matchable'])),
            'unmapped_skills' => array_values(array_unique($unmapped)),
            // مهارات إلزامية عملية: لا يستوفيها طالب إلا بتحقق بشري تسجله إدارة المنصة
            'skills_requiring_human_verification' => array_values(array_unique($untested)),
        ];
    }

    /** @return array<string, array{id: int, name: string}> مفهرسة بالاسم بحروف صغيرة */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (Skill::query()->orderBy('name')->get(['id', 'name']) as $skill) {
            $catalog[mb_strtolower(trim($skill->name))] = ['id' => $skill->id, 'name' => $skill->name];
        }

        return $catalog;
    }

    /** @return array<int, string> */
    private static function skillNames(mixed $names): array
    {
        if (! is_array($names)) {
            return [];
        }

        $clean = [];
        foreach ($names as $name) {
            $name = self::text($name, 80);
            if ($name !== '' && ! in_array(mb_strtolower($name), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $name;
            }
        }

        return array_slice($clean, 0, self::MAX_SKILLS_PER_ROLE);
    }

    private static function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }
}
