<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * جلسة تقييم مكتوب. questions تحوي معايير التصحيح والإجابة المرجعية ولذلك مخفية من JSON؛ ما يُعرض
 * للطالب يُبنى حقلًا بحقل في AssessmentSessionService.
 */
class AssessmentSession extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    /** انتهت الإجابة (اكتملت أو انتهى الوقت) والتصحيح لم يكتمل بعد */
    public const STATUS_GRADING = 'grading';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'student_profile_id',
        'professional_specialization',
        'status',
        'open_slot',
        'title',
        'skill_ids',
        'questions',
        'question_count',
        'settings',
        'generation',
        'started_at',
        'expires_at',
        'answering_ended_at',
        'ended_by_timeout',
        'completed_at',
        'last_grading_error',
    ];

    protected $hidden = ['questions', 'settings', 'generation'];

    protected function casts(): array
    {
        return [
            'skill_ids' => 'array',
            'questions' => 'array',
            'settings' => 'array',
            'generation' => 'array',
            'open_slot' => 'integer',
            'question_count' => 'integer',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'answering_ended_at' => 'datetime',
            'ended_by_timeout' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AssessmentSessionAnswer::class)->orderBy('position');
    }

    public function results(): HasMany
    {
        return $this->hasMany(SkillAssessment::class);
    }
}
