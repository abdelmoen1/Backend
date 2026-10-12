<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نتيجة تقييم مهارة. لا يملك الطالب أي مسار API لإنشائها؛ تُسجَّل فقط من تصحيح جلسة تقييم مكتوبة
 * (source = ai_written_assessment، نتيجة لكل مهارة مع assessment_session_id)، أو من إدارة المنصة،
 * أو من اختبار الاختيار من متعدد القديم (platform_test). وهي وحدها ما يجعل المهارة "مُتحقق منها"
 * في المطابقة (انظر SkillVerification للمهارات العملية). السجل إضافي فقط: نتيجة ناجحة لا تُستبدل.
 *
 * level: المستوى الذي تخصه النتيجة (SkillLevels). النجاح بمستوى يثبت ذلك المستوى فقط؛ المستوى المُثبت للمهارة
 * هو أعلى مستوى ناجح يُحتسب، فلا يخفضه رسوب لاحق بأي مستوى. النتائج السابقة لإضافة المستويات تُعد "مبتدئ".
 *
 * incomplete: تعذر تصحيح إحدى إجابات المهارة (خلل في الذكاء الاصطناعي وليس أداء الطالب)؛ لا نجاح ولا مهلة إعادة.
 */
class SkillAssessment extends Model
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUSES = [self::STATUS_PASSED, self::STATUS_FAILED, self::STATUS_INCOMPLETE];

    /** اختبار الاختيار من متعدد القديم (لم يعد مسار التقييم) */
    public const SOURCE_PLATFORM_TEST = 'platform_test';

    public const SOURCE_AI_WRITTEN = 'ai_written_assessment';

    protected $fillable = [
        'student_profile_id',
        'skill_id',
        'skill_test_attempt_id',
        'assessment_session_id',
        'status',
        'level',
        'score',
        'source',
        'recorded_by_user_id',
        'notes',
        'assessed_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'assessed_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AssessmentSession::class, 'assessment_session_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SkillTestAttempt::class, 'skill_test_attempt_id');
    }
}
