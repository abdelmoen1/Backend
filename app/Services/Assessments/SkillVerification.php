<?php

namespace App\Services\Assessments;

use App\Models\Skill;
use App\Models\SkillAssessment;

/**
 * متى تُحتسب نتيجة "ناجح" في مطابقة المشاريع. المهارات العملية (config/assessments.php) لا تثبتها
 * إجابة مكتوبة: نتيجتها من مصدر آلي (اختبار مكتوب) تُعرض للطالب لكنها لا تُحتسب، ويلزمها تحقق بشري
 * مسجل بالأمر skills:record-assessment.
 */
final class SkillVerification
{
    /** مصادر آلية: تقييم Gemini المكتوب، واختبار الاختيار من متعدد القديم */
    public const AUTOMATED_SOURCES = [SkillAssessment::SOURCE_AI_WRITTEN, SkillAssessment::SOURCE_PLATFORM_TEST];

    /** @return array<int, int> */
    public static function practicalSkillIds(): array
    {
        $names = array_map('mb_strtolower', array_filter((array) config('assessments.practical_skills', []), 'is_string'));
        if ($names === []) {
            return [];
        }

        return Skill::query()
            ->get(['id', 'name'])
            ->filter(fn (Skill $skill): bool => in_array(mb_strtolower($skill->name), $names, true))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public static function counts(SkillAssessment $result, array $practicalSkillIds): bool
    {
        return $result->status === SkillAssessment::STATUS_PASSED
            && (! in_array((int) $result->skill_id, $practicalSkillIds, true)
                || ! in_array($result->source, self::AUTOMATED_SOURCES, true));
    }
}
