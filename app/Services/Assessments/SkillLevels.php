<?php

namespace App\Services\Assessments;

/**
 * مستويات الإتقان المعتمدة في المنصة، بترتيب تصاعدي. تُستخدم في ثلاثة مواضع منفصلة:
 * - المستوى المطلوب لمحاولة التقييم (assessment_sessions.requested_level): طلب صعوبة يختاره الطالب، لا إثبات.
 * - المستوى المُثبت بنتيجة (skill_assessments.level): لا يُحتسب إلا مع status = passed.
 * - الحد الأدنى المطلوب لمهارة إلزامية في دور (project_role_skills.min_level): تؤكده المؤسسة قبل النشر.
 * مستوى الإتقان الذي يختاره الطالب في student_skills.proficiency إعلان ذاتي ولا علاقة له بأي من ذلك.
 */
final class SkillLevels
{
    public const BEGINNER = 'beginner';

    public const INTERMEDIATE = 'intermediate';

    public const ADVANCED = 'advanced';

    public const EXPERT = 'expert';

    /** بترتيب تصاعدي: مبتدئ < متوسط < متقدم < خبير */
    public const ALL = [self::BEGINNER, self::INTERMEDIATE, self::ADVANCED, self::EXPERT];

    public const LABELS = [
        self::BEGINNER => 'مبتدئ',
        self::INTERMEDIATE => 'متوسط',
        self::ADVANCED => 'متقدم',
        self::EXPERT => 'خبير',
    ];

    public static function valid(mixed $level): bool
    {
        return is_string($level) && in_array($level, self::ALL, true);
    }

    /** 1..4؛ 0 لقيمة غير معروفة (لا تستوفي أي حد أدنى). */
    public static function rank(?string $level): int
    {
        $index = $level === null ? false : array_search($level, self::ALL, true);

        return $index === false ? 0 : $index + 1;
    }

    public static function atLeast(?string $level, string $required): bool
    {
        return self::rank($level) > 0 && self::rank($level) >= self::rank($required);
    }

    public static function label(?string $level): ?string
    {
        return $level === null ? null : (self::LABELS[$level] ?? null);
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(fn (string $level): array => ['key' => $level, 'label' => self::LABELS[$level]], self::ALL);
    }
}
