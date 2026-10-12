<?php

namespace App\Services\Projects;

use App\Models\Skill;
use App\Models\Specialization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * تخصص الطالب (professional_specialization) وتوافقه مع فئات أدوار المشاريع حسب جدول specializations
 * (SpecializationSeeder). المطابقة على المفتاح حرفيًا؛ التخصص الجامعي لا يدخل في المطابقة.
 */
final class SpecializationRoles
{
    public const COMPATIBLE = 'compatible';

    /** قيمة محفوظة ليست من القائمة الحالية (مثل تخصص لم يعد موجودًا) */
    public const UNRECOGNIZED = 'unrecognized';

    public const MISSING = 'missing';

    /** @return array<string, array{label: string, roles: array<int, string>}> */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::ordered()->get() as $specialization) {
            $catalog[$specialization->key] = [
                'label' => $specialization->name,
                'roles' => self::roles($specialization),
            ];
        }

        return $catalog;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return self::ordered()->pluck('key')->all();
    }

    /**
     * @return array{status: string, key: ?string, label: ?string, categories: array<int, string>, labels: array<int, string>}
     */
    public static function for(?string $key): array
    {
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;
        $specialization = $key === null ? null : Specialization::query()->where('key', $key)->first();
        $categories = $specialization ? self::roles($specialization) : [];

        return [
            'status' => match (true) {
                $key === null => self::MISSING,
                $specialization === null => self::UNRECOGNIZED,
                default => self::COMPATIBLE,
            },
            'key' => $key,
            'label' => $specialization?->name,
            'categories' => $categories,
            'labels' => array_map(fn (string $category): string => ProjectStaffing::ROLE_CATEGORIES[$category], $categories),
        ];
    }

    public static function allows(?string $key, string $category): bool
    {
        return in_array($category, self::for($key)['categories'], true);
    }

    /** @return Collection<int, int> معرّفات مهارات التخصص؛ فارغة لتخصص غير موجود */
    public static function skillIds(string $key): Collection
    {
        $specialization = Specialization::query()->where('key', $key)->first();

        return $specialization
            ? $specialization->skills()->pluck('skills.id')->map(fn ($id): int => (int) $id)
            : collect();
    }

    /**
     * القائمة للواجهة مع مهارات كل تخصص بمعرّفاتها الحقيقية.
     *
     * @return array<int, array{key: string, label: string, title: string, description: ?string, role_labels: array<int, string>, skills: array<int, array{id: int, name: string}>}>
     */
    public static function publicCatalog(): array
    {
        return self::ordered()
            ->with('skills:id,name')
            ->get()
            ->map(fn (Specialization $specialization): array => [
                'key' => $specialization->key,
                'label' => $specialization->name,
                'title' => $specialization->title,
                'description' => $specialization->description,
                'role_labels' => array_map(
                    fn (string $category): string => ProjectStaffing::ROLE_CATEGORIES[$category],
                    self::roles($specialization),
                ),
                'skills' => $specialization->skills
                    ->map(fn (Skill $skill): array => ['id' => $skill->id, 'name' => $skill->name])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    private static function ordered(): Builder
    {
        return Specialization::query()->orderBy('sort_order')->orderBy('id');
    }

    /** فئات معروفة فقط، بترتيب ProjectStaffing::ROLE_CATEGORIES */
    private static function roles(Specialization $specialization): array
    {
        return array_values(array_intersect(
            array_keys(ProjectStaffing::ROLE_CATEGORIES),
            (array) $specialization->role_categories,
        ));
    }
}
