<?php

namespace App\Services\Projects;

use App\Models\Skill;

/**
 * المسار المهني للطالب (professional_specialization) وتوافقه مع فئات أدوار المشاريع حسب
 * config/matching.php. المطابقة على المفتاح حرفيًا؛ التخصص الجامعي لا يدخل في المطابقة.
 */
final class SpecializationRoles
{
    public const COMPATIBLE = 'compatible';

    /** قيمة محفوظة ليست من القائمة الحالية (مثل مسار حُذف من الإعدادات) */
    public const UNRECOGNIZED = 'unrecognized';

    public const MISSING = 'missing';

    /** @return array<string, array{label: string, roles: array<int, string>, suggested_skills: array<int, string>}> */
    public static function catalog(): array
    {
        $catalog = [];
        foreach ((array) config('matching.professional_specializations', []) as $key => $definition) {
            if (! is_string($key) || ! is_array($definition) || ! is_string($definition['label'] ?? null)) {
                continue;
            }
            $catalog[$key] = [
                'label' => $definition['label'],
                // فئات معروفة فقط
                'roles' => array_values(array_intersect(array_keys(ProjectStaffing::ROLE_CATEGORIES), (array) ($definition['roles'] ?? []))),
                'suggested_skills' => array_values(array_filter((array) ($definition['suggested_skills'] ?? []), 'is_string')),
            ];
        }

        return $catalog;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * @return array{status: string, key: ?string, label: ?string, categories: array<int, string>, labels: array<int, string>}
     */
    public static function for(?string $key): array
    {
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;
        $definition = $key === null ? null : (self::catalog()[$key] ?? null);
        $categories = $definition['roles'] ?? [];

        return [
            'status' => match (true) {
                $key === null => self::MISSING,
                $definition === null => self::UNRECOGNIZED,
                default => self::COMPATIBLE,
            },
            'key' => $key,
            'label' => $definition['label'] ?? null,
            'categories' => $categories,
            'labels' => array_map(fn (string $category): string => ProjectStaffing::ROLE_CATEGORIES[$category], $categories),
        ];
    }

    public static function allows(?string $key, string $category): bool
    {
        return in_array($category, self::for($key)['categories'], true);
    }

    /**
     * القائمة للواجهة مع المهارات المقترحة بمعرّفاتها الحقيقية.
     *
     * @return array<int, array{key: string, label: string, role_labels: array<int, string>, suggested_skills: array<int, array{id: int, name: string}>}>
     */
    public static function publicCatalog(): array
    {
        $skills = Skill::query()->get(['id', 'name'])->keyBy(fn (Skill $skill): string => mb_strtolower($skill->name));

        $list = [];
        foreach (self::catalog() as $key => $definition) {
            $suggested = [];
            foreach ($definition['suggested_skills'] as $name) {
                $skill = $skills->get(mb_strtolower($name));
                if ($skill) {
                    $suggested[] = ['id' => $skill->id, 'name' => $skill->name];
                }
            }
            $list[] = [
                'key' => $key,
                'label' => $definition['label'],
                'role_labels' => array_map(fn (string $category): string => ProjectStaffing::ROLE_CATEGORIES[$category], $definition['roles']),
                'suggested_skills' => $suggested,
            ];
        }

        return $list;
    }
}
