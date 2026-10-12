<?php

namespace App\Services\Assessments;

use App\Models\Skill;
use App\Models\SkillAssessment;

/**
 * متى تُحتسب نتيجة "ناجح" في مطابقة المشاريع، وبأي مستوى. المهارات العملية (config/assessments.php) لا
 * تثبتها إجابة مكتوبة: نتيجتها من مصدر آلي (اختبار مكتوب) تُعرض للطالب لكنها لا تُحتسب، ويلزمها تحقق بشري
 * مسجل بالأمر skills:record-assessment. المستوى المُثبت هو أعلى مستوى لنتيجة محتسبة، فلا يخفضه رسوب لاحق.
 */
final class SkillVerification
{
    /** مصادر آلية: تقييم Gemini المكتوب، واختبار الاختيار من متعدد القديم */
    public const AUTOMATED_SOURCES = [SkillAssessment::SOURCE_AI_WRITTEN, SkillAssessment::SOURCE_PLATFORM_TEST];

    /** @return array<int, int> */
    public static function practicalSkillIds(): array
    {
        return self::idsFor('assessments.practical_skills');
    }

    /** @return array<int, int> مهارات أسئلتها العملية سيناريوهات ومهام بدل الكود */
    public static function nonProgrammingSkillIds(): array
    {
        return self::idsFor('assessments.non_programming_skills');
    }

    public static function counts(SkillAssessment $result, array $practicalSkillIds): bool
    {
        return $result->status === SkillAssessment::STATUS_PASSED
            && (! in_array((int) $result->skill_id, $practicalSkillIds, true)
                || ! in_array($result->source, self::AUTOMATED_SOURCES, true));
    }

    /** مستوى النتيجة؛ النتائج السابقة لإضافة المستويات (NULL) تُعد "مبتدئ". */
    public static function levelOf(SkillAssessment $result): string
    {
        return SkillLevels::valid($result->level) ? $result->level : SkillLevels::BEGINNER;
    }

    /**
     * أعلى مستوى مُثبت بين نتائج مهارة واحدة، أو null إن لم توجد نتيجة محتسبة.
     *
     * @param  iterable<SkillAssessment>  $results
     */
    public static function highestLevel(iterable $results, array $practicalSkillIds): ?string
    {
        $best = null;
        foreach ($results as $result) {
            if (self::counts($result, $practicalSkillIds) && SkillLevels::rank(self::levelOf($result)) > SkillLevels::rank($best)) {
                $best = self::levelOf($result);
            }
        }

        return $best;
    }

    /** @return array<int, int> */
    private static function idsFor(string $key): array
    {
        $names = array_map('mb_strtolower', array_filter((array) config($key, []), 'is_string'));
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
}
