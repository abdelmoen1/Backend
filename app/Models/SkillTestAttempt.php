<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * قديم (legacy): اختبارات الاختيار من متعدد استُبدلت بجلسات التقييم المكتوب (AssessmentSession).
 * الجدول والنموذج باقيان للحفاظ على أي نتائج سابقة مرتبطة به؛ لا يوجد مسار يكتب فيه الآن.
 */
class SkillTestAttempt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'student_profile_id',
        'skill_test_id',
        'content_version',
        'status',
        'open_slot',
        'questions',
        'answers',
        'question_count',
        'pass_percentage',
        'correct_count',
        'started_at',
        'expires_at',
        'finished_at',
    ];

    protected $hidden = ['questions', 'answers'];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'answers' => 'array',
            'content_version' => 'integer',
            'open_slot' => 'integer',
            'question_count' => 'integer',
            'pass_percentage' => 'integer',
            'correct_count' => 'integer',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(SkillTest::class, 'skill_test_id');
    }

    public function result(): HasOne
    {
        return $this->hasOne(SkillAssessment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }
}
